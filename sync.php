<?php
require __DIR__ . '/vendor/autoload.php';
use Aranyasen\HL7\Message;
use Aranyasen\HL7\Messages\ACK;
use Aranyasen\HL7\Segments\MSH;
use GetOpt\GetOpt as Getopt;
use GetOpt\Option;

define('NAME', 'COVID19-OpenEMR-CAIR');
define('VERSION', '.2');

$opt = new GetOpt([

    Option::create('v', 'verbose', GetOpt::NO_ARGUMENT)
        ->setDescription('increase script verbosity'),

    Option::create(null, 'version', GetOpt::NO_ARGUMENT)
        ->setDescription('increase script verbosity'),

    Option::create(null, 'help', GetOpt::NO_ARGUMENT)
        ->setDescription('Show this help text'),

    Option::create(null, 'dbuser', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The openemr database username.')
	->setDefaultValue('openemr'),

    Option::create(null, 'dbpass', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The openemr database password.')
	->setDefaultValue('openemr'),

    Option::create(null, 'dbname', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The openemr database name.')
	->setDefaultValue('openemr'),

    Option::create(null, 'cairuser', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The CAIR user name.'),

    Option::create(null, 'cairpass', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The CAIR password.'),

    Option::create(null, 'cairfacility', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The CAIR facility ID. aka CAIR org code (ie. DE-011990)'),

    Option::create(null, 'cairregioncode', GetOpt::REQUIRED_ARGUMENT )
        ->setDescription('The CAIR region code (ie. CAIRBA).')
	->setDefaultValue('CAIRBA'),

]);

// process arguments and catch user errors
try {
    try {
        $opt->process();
    } catch (Missing $exception) {
        // catch missing exceptions if help is requested
        if (!$opt->getOption('help')) {
            throw $exception;
        }
    }
} catch (ArgumentException $exception) {
    file_put_contents('php://stderr', $exception->getMessage() . PHP_EOL);
    echo PHP_EOL . $getOpt->getHelpText();
    exit;
}

// show version and quit
if ($opt->getOption('version')) {
    echo sprintf('%s: %s' . PHP_EOL, NAME, VERSION);
    exit;
}

// show help and quit
if ($opt->getOption('help')) {
    echo $opt->getHelpText();
    exit;
}

// Connect to the OpenEMR database
DB::$user = $opt->getOption('dbuser');
DB::$password = $opt->getOption('dbpass');
DB::$dbName = $opt->getOption('dbname');

// open syslog, include the process ID and also send
// the log to standard error, and use a user defined
// logging mechanism
// LOG_USER is the only valid log type under Windows operating systems
// and many OpenEMR implementations are Windows based

openlog(NAME .': '. VERSION, LOG_PID | LOG_PERROR, LOG_USER);

// This script depends on a new table in the database to track what has been 
// sent and received to CAIR, this will make sure everything is set up
db_init();

// Get list of COVID-19 immunizations that have not been sent to CAIR

$cair_responses = DB::query(
	"select max(id) id,immunization_id,ackcode from immunizations_cair ".
	"group by immunization_id, ackcode"
);
$icids = array();
foreach ($cair_responses as $response) {
	if ($response['ackcode'] === 'A') {
		$icids[] = $response['immunization_id'];
	}
}

// Extra Points: use the openemr api instead of querying the database directly, bonus
// points for FHIR API
$query =
	"select immunizations.id,immunizations.patient_id,immunizations.cvx_code,".
	"immunizations.amount_administered,immunizations.amount_administered_unit,".
	"immunizations.lot_number,immunizations.expiration_date,".
	"immunizations.manufacturer,immunizations.ordering_provider, ".
	"immunizations.administered_by_id ".
	"from immunizations where ";

if (count($icids)>0) {
	$query .=
	"id not in (". implode(",", $icids) .") ";
}

// https://www.cdc.gov/vaccines/programs/iis/COVID-19-related-codes.html
// This limits vaccinations to the COVID19 vaccines
$query .= "and immunizations.cvx_code >= 207 and immunizations.cvx_code <= 212";

$immunizations = DB::query($query);

$nowdate = date('Ymd');
foreach ($immunizations as $immunization) {

	$patient = DB::query(
		"select * ".
		"from patient_data ".
		"where pid = ". $immunization['patient_id'] ." ".
		"limit 1", 
	);
	$patient = array_pop($patient);

	syslog(LOG_DEBUG, 'participant: "'. $patient['fname'] .'" "'. $patient['lname'] 
		.'" ('. $immunization['patient_id'] .") lot: ". 
		$immunization['lot_number'] .", immunization: ". $immunization['id']);

	if (!isset($patient['lname']) || is_null($patient['lname']) || $patient['lname'] === '') {
		syslog(LOG_ERR, "Skipping because we do not have last name");
		continue;
	}

	if (!isset($patient['fname']) || is_null($patient['fname']) || $patient['fname'] === '') {
		syslog(LOG_ERR, "Skipping because we do not have first name");
		continue;
	}

	if (!isset($patient['DOB']) || is_null($patient['DOB'])) {
		syslog(LOG_ERR, "Skipping because we do not have a DOB");
		continue;
	}

	if (!isset($immunization['lot_number']) || is_null($immunization['lot_number'])) {
		syslog(LOG_ERR, "Skipping because we do not have a lot number");
		continue;
	}

	// GENERATE HL7 FILE
	// Build out HL7 messages with the minimum nesesary info

	// MSH: Message Header Segment
	// The Message Header (MSH) segment is required for each message sent. 
	// Multiple messages may be sent back-to-back. MSH segments separate 
	// multiple messages.
	$hl7 = "MSH|^~\&|OPENEMR|";

	// MSH-4: Sending facility
	$hl7 .= $opt->getOption('cairfacility') ."|";

	// MSH-5
	$hl7 .= "|";

	// MSH-6
	// Region Code
	// See Appendix A: http://cairweb.org/docs/CAIR2_HL7v2.5.1DataExchangeSpecs.pdf
	$hl7 .= $opt->getOption('cairregioncode') .'|';

	// MSH-7
	// Date/time of message
	$hl7 .= date('YmdHis') ."+0000||";

	// MSH-9
	// VXU^V04^VXU_V04
	$hl7 .= "VXU^V04^VXU_V04|";

	// MSH-10
	// Message control ID (must be unique for a given day) Required
	// Used to tie ack to message
	$hl7 .= date('Ymdhms') . $immunization['patient_id'] . $immunization['cvx_code'] ."|";

	$hl7 .= "P|";

	// MSH-12 version 2.5.1 only
	$hl7 .= "2.5.1|||";

	// MSH-15 accept ack type
	$hl7 .= "ER|";

	// MSH-16 application ack type
	$hl7 .= "AL|||||";

	// MSH-21 message profile indicator
	// sites may use this field to assert adherence to, or reference,
	// a message profile.  It is not clear to me how this is used.  The commented
	// value below is what is used in the CAIR example. 
	// pflieger uses a slightly different value here: 
	// https://github.com/growlingflea/openemr/blob/65dd69e09eb7b038031cca2d7f9e6b8b3e49e192/interface/reports/immunization_report.php#L256
	//$hl7 .= "Z22^CDCPHINVS|";
	$hl7 .= "|";

	/* Responsible Sending Org
	CAIR site ID in MSH-22, must match the CAIR site
	ID of the site where the vaccine inventory will be
	drawn from. 
	*/
	$hl7 .= $opt->getOption('cairfacility') ."\n";


	// PID: Patient identifier segment
	// The Patient Identifier segment includes essential information for matching
	// an incoming patient record to patient records previously sent by other 
	// providers
	$hl7 .= "PID|" . // [[ 3.72 ]]
		    "1|" . // 1. Set id
		    "|";

	// PID-3 This is the patient id from the providers system, commonly 
        // referred to as medical record number 
	// "^^^MPI&2.16.840.1.113883.19.3.2.1&ISO^MR" Patient indentifier list.
	$hl7 .= $immunization['patient_id'] .'^^^OPENEMR^MR|';

	$hl7 .= '|';

	// PID-5: Patient name
	// The legal name must be sent in the first repetition. The last, 
	// first and middle names must be alpha characters only (A-Z). 
	// The last name or the given name should not contain the patient's 
	// suffix (e.g. JR or III). The given name should not include the patient's 
	// middle name or middle initial. These should be sent in their 
	// appropriate fields

	// Patient last name must be greater than 1-character in length
	// There can't be any numbers in names either
	// We should not modify this to get it past, instead we should 
	// update our participant record
	/* $hl7 .= sprintf("%'W10s", $patient['lname']) .'^'
		. sprintf("%'W10s", $patient['fname']) .'^^^^^L|'; */
	$hl7 .= $patient['lname'] .'^'
		. $patient['fname'] .'^^^^^L|';

	// PID-6 Mother's Maiden name
	$hl7 .= '|';

	// PID-7 Date of birth
	$hl7 .= preg_replace('/-/', '', $patient['DOB']) .'|';

	// PID-8 Sex ‘M’, ‘F’, ‘X’ or ‘U’ only
	if ($patient['sex'] == 'Male') {
		$hl7 .= 'M|'; 
	} else if ($patient['sex'] == 'Female') {
		$hl7 .= 'F|'; 
	} else if ($patient['sex'] == '' || $patient['sex'] == 'Unknown') {
		$hl7 .= 'U|'; 
	} else {
		$hl7 .= 'X|'; 
	}

	// PID-9
	$hl7 .= '|';

	// PID-10 Race
	// https://www.hl7.org/fhir/v3/Race/cs.html
	// Submitting this field correclty is important for COVID-19 equity.
	// <hl7_race_code>^<hl7_race_text>^HL7005
	// OpenEMR ships with the <hl7_race_text> and <hl7_race_code> in the
	// title and notes field of the list_options table

	// The use of fields PID-10 (Race) and PID-22 (Ethnic Group) is forbidden in France.
	// https://wiki.ihe.net/index.php/HL7_Tables

	// There is no capacity for multiple races in the hl7 PID-10 format
	// If we receive a race with multiple fields this section will fail
	// and we will send a empty value for race.
	if (!empty($patient['race'])) {
		$race = DB::query(
			'select title,notes '.
			'from list_options '.
			'where option_id = "'.  $patient['race'] .'" '.
			'and list_id = "race"'
		);
		$race = array_pop($race);
		if (!empty($race['title']) && !empty($race['notes'])) {
			$hl7 .= $race['notes'] .'^'. $race['title'] .'^HL7005|';
		} else {
			$hl7 .= '|';
		}
	} else {
		$hl7 .= '|';
	}

	// PID-11 Participant address
	$hl7 .= '|';

	// PID-12
	$hl7 .= '|';

	// PID-13 Home phone number
	$hl7 .= '|';

	// PID-14
	$hl7 .= '|';

	// PID-15 Primary language
	$hl7 .= '|';

	// PID-16 21 
	$hl7 .= '||||||';

	// PID-22 ethnic group
	// This field is important for COVID-19 equity
	if (!empty($patient['ethnicity']) && $patient['ethnicity'] === 'hisp_or_latin') {
		// 2135-2 Hispanic or Latino
		$hl7 .= '2135-2^Hispanic or Latino^CDCREC|';
	} else {
		// This is the same as sending not hispanic or latino
		$hl7 .= '|';
	}

	// PID-23
	$hl7 .= '|';

	// PID-24 multiple birth indicator
	$hl7 .= '|';

	// PID-25 birth order
	$hl7 .= '|';

	// PID-26 - 28
	$hl7 .= '|||';

	// PID-29 patient death date and time
	$hl7 .= '|';

	// PID-30 patient death indicator
	$hl7 .= "|\n";

	// Fill out the PD1 header
	// Ex: PD1|||||||||||02^REMINDER/RECALL – ANY METHOD^HL70215|N|20140730|||A|20140730|

	$hl7 .=  "PD1|" . // Patient Additional Demographic Segment
		"|". // 1. 
		"|". // 2. 
		"|". // 3. 
		"|". // 4. 
		"|". // 5. 
		"|". // 6. 
		"|". // 7. 
		"|". // 8. 
		"|". // 9. 
		"|". // 10. 
		"|". // 11. This field indicates whether the patient wishes to 
		     // receive reminder/recall notices. Use this field to indicate a 
                     // specific request from the patient/parent or leave blank. 
                     // An empty value will be treated the same as a “02” value in this 
                     // field, meaning that it is OK for a provider site to send 
                     // reminder/recall notices regarding immunizations to this patient

		"N|".// 12. Protection Indicator
                     // This field identifies whether a person’s information 
                     // may be shared with other CAIR2 users. The protection 
                     // state must be actively determined by the clinician. 
                     // CAIR will translate an empty value sent in PD1-12 as 
                     // disclosed/agree to share. 

                     // This is the record lock field.  If you answer N here
		     // only the submitting organization will be able to access
		     // the record in CAIR. We default to N here and have 
		     // participants call in to unlock their record.  Pflieger's
		     // additions to the immunizations system include an additional
		     // field in patient_data data_sharing and data_sharing_date that
		     // support recording participant submitted feedback for this.
		     

		// 13. Protection Indicator Effective Date
		date("Ymd")."|".

		"|" . // 14. 
		"|" . // 15. 

		"A|". // 16. Immunization Registry Status

		// 17. Immunization Registry Status Effective Date [If the PD1-16 (Registry Status)field is valued.]
		date('Ymd') ."|\n" ;

	// The NK1 segment is only used if we are documenting a care giver / next of kin relationship
	// NK1|1|JONES^MARTHA^^^^^L|MTH^MOTHER^HL70063|1234 W FIRST ST^^BEVERLY HILLS^CA^90210^^H|^PRN^PH^^^555^5555555| 
	// $hl7 .= "NK1||||||\n";

	// ORC|RE||197023^CMC|||||||^Clark^Dave||1234567890^Smith^Janet^^^^^^NPPES^L^^^NPI^^^^^^^^MD
	// We are hardcoding Chuck's info here
	$hl7 .= "ORC|RE||";
	if (isset($immunization['id'])) {
		// ORC-3 Filler order number.  This should be unique per
		// submitting organization
		$hl7 .= $immunization['id'];
	}

	// ORC-12: This shall be the provider ordering the immunization. 
	// It is expected to be empty if the immunization record is transcribed from 
	// an historical record

	// This field contains the identity of the person who is responsible for 
	// creating the request (i.e., ordering physician). In the case where this 
	// segment is associated with a historic immunization record and the 
	// ordering provider is not known, then this field should not be populated

	// NOTE: Immunization providers that are participating in the Department 
	// of Health Care Services, Value Based Payment Program (VBP) must have 
	// the ORC-12 field submitted to CAIR2 and populated as shown in the example 
	// below, in order to be properly counted for the VBP immunization measure.
	// This field must contain the ordering provider’s NPI number in ORC-12.1 and
	// the Identifier Type Code “NPI” in ORC-12.13. An example of the ORC-12 
	// field formatting is as follows

	$query =
	"select users.npi,users.fname,users.lname,list_options.title ".
	"from users ".
	"left join list_options on (users.physician_type = list_options.option_id) ".
	"where users.id = ". $immunization['ordering_provider'];

	$results = DB::query($query);
	$result = $results[0];

	// If we do not have the all of the ordering provider info then
	// submit a blank ORC-12
	if (empty($result) || empty($result['npi']) || empty($result['fname']) ||
		empty($result['lname']) || empty(['title'])) {
		syslog(LOG_WARNING, 'Did not receive complete ordering provider info. '.
			'Sending blank ORC-12.');
		$hl7 .= "|||||||||\n";
	} else {
		$hl7 .= "|||||||||".
			$result['npi'] .'^'. $result['lname'] .'^'. $result['fname']
			.'^^^^^^NPPES^L^^^NPI^^^^^^^^'. $result['title'] ."\n";
	}

	// RXA: Pharmacy/Treatment Administration Segment

	// The RXA segment carries pharmacy administration data. This segment 
	// is required to indicate which vaccinations are given. This segment
	// is required if there are vaccinations to report. All vaccinations 
	// should be reported in one message, not in separate messages

	// RXA|0|1|20140730||08^HEPB-PEDIATRIC/ADOLESCENT^CVX|.5|mL^mL^UCUM||
	// 00^NEW IMMUNIZATION RECORD^NIP001|
	// 1234567890^Smith^Janet^^^^^^NPPES^^^^NPI^^^^^^^^MD |
	// ^^^DE-000001||||0039F|20200531|MSD^MERCK^MVX|||CP|A
	$hl7 .= "RXA|0|1|"
		. date('Ymd') .'||';

	// RXA-5
	$hl7 .= sprintf("%02d", $immunization['cvx_code']) .'^^CVX|';

	// RXA-6 Administered Amount
	// required (if amount is unknown, use ‘999’)
	if (isset($immunization['amount_administered'])) {
		$hl7 .= $immunization['amount_administered'];
	$hl7 .= '|mL^mL^UCUM|';
	} else {
		$hl7 .= '999||';
	}
	$hl7 .= '|';

	// RXA-9: Administration notes
	// Until we do an import of our historical records we will not 
	// want to change this field.  Inventory decrements depend on this
	// value being 00
	$hl7 .= '00^NEW IMMUNIZATION RECORD^NIP001|';

	// RXA-10: Administering Provider
	// We are going to leave this blank for now
	$hl7 .= '|';

	// RXA-11: Administered at location
	// The administered at location is used to indicate the facility at which 
        // the immunization was given. The facility (CAIR2 org code) should 
	// be sent in position 4.
	$hl7 .= '^^^'. $opt->getOption('cairfacility') .'||||';

	// RXA-15: Substance Lot Number
	// required is administered dose
	if (isset($immunization['lot_number'])) {
		$hl7 .= $immunization['lot_number'];
	}
	$hl7 .= '|';

	// RXA-16: Substance Expiration Date
	// This field contains the expiration date of the vaccine 
	// administered. Note that vaccine expiration date does not always 
	// have a “day” component; therefore use the last day of the month 
	// for the ‘day’ component of the expiration date.. Format:  YYYYMMDD
	if (!isset($immunization['expiration_date'])) {
		$hl7 .= date('Ymd', $immunization['expiration_date']) .'|';
	} else {
		$hl7 .= '|';
	}

	// RXA-17: Substance manufacturer	
	if (isset($immunization['manufacturer'])) {
		$hl7 .= $immunization['manufacturer'] .'^^MVX|';
	} else {
		$hl7 .= '|';
	}
	$hl7 .= '||';

	// RXA-20: Completion Status
	$hl7 .= 'CP|';

	// RXA-21: Action coded
	$hl7 .= "A\n";

	// RXR|C28161^INTRAMUSCULAR^NCIT|LA^LEFT ARM^HL70163
	// Every RXA segment in a VXU may have zero or one RXR segment
	// $hl7 .= "RXR||\n";

	// OBX: Observation Segment
	// The OBX segment will be used to record Vaccine Eligibility by vaccine dose
	// OBX|1|CE|64994-7^Vaccine funding program eligibility category^LN|1|V03^VFC eligibility – Uninsured^HL70064||||||F|||20110701140500
	$hl7 .= 'OBX|';

	// OBX-1: Set ID - OBX
	$hl7 .= '1|';

	// OBX-2: Value Type
	$hl7 .= 'CE|';

	// OBX-3: Observation Identifier
	$hl7 .= '64994-7^^LN|';

	// OBX-4: Observation Sub-ID
	$hl7 .= '1|';

	// OBX-5: Observation value
	$hl7 .= 'V01^^HL70064|';

	$hl7 .= '|||||F|||';

	$hl7 .= date('YmdHis');

	// Submit the pending vaccinations to CAIR
	// use zend-soap
	// Accept response compression
	$client = new Laminas\Soap\Client( __DIR__ .'/CAIR.wsdl', 
		array('compression' => SOAP_COMPRESSION_ACCEPT)
	);

	$args = [
		'username' => $opt->getOption('cairuser'),
		'password' => $opt->getOption('cairpass'),
		'faciltyID' => $opt->getOption('cairfacility'),
		'hl7Message' => $hl7
	];

	syslog(LOG_DEBUG, 'Ready to submit to CAIR: '. print_r($args, 1));

	try {
		$response = $client->submitSingleMessage($args);
	} catch (Exception $e) {
		syslog(LOG_ERR, 'Unable to submit VAX confirmation to CAIR: '.
			$e->getMessage());	
	}

	$hl7Response = $response->return;
	if (strpos($hl7Response, 'MSH') === false) {
	    syslog(LOG_ERR, "Failed to send HL7");
	}

	record_result($hl7, $hl7Response, $immunization['id']);
} // foreach unregistered immunization

//EXAMPLE ACK MESSAGES GENERATED BY CAIR2: 

// WARNING (Informational)
// MSH|^~\&|CAIR IIS4.0.0|CAIR IIS||UATPARENT|20160630||ACK^V04^ACK|TEST001|P|2.5.1||||||||||CAIR IIS|UATPARENTMSA|AE|1791129 ERR||RXA^1^10^1^13|0^Message accepted^HL70357|W|5^Table value not found^HL70533|||Informational error - No value was entered for RXA-10.13

// ERROR (Message Rejected)
// MSH|^~\&|CAIR IIS4.0.0|CAIR IIS||UATPARENT|20160630||ACK^V04^ACK|TEST001|P|2.5.1||||||||||CAIR IISMSA|AE|1791129 ERR||PID^1^3^0|101^Required field missing^HL70357|E|6^Required observation missing^HL70533|||MESSAGE REJECTED - REQUIRED FIELD PID-3-5 MISSING

// APPLICATION REJECTION
// MSH|^~\&|CAIR IIS4.0.0|CAIR IIS||UATPARENT|20160630||ACK^V04^ACK|TEST001|P|2.5.1||||||||||CAIR IIS|UATPARENTMSA|AR|1791129 ERR||MSH^1^11|202^Unsupported processing ID^HL70357|E|4^Invalid value^HL70533|||MESSAGE REJECTED. INVALID PROCESSING ID. MUST BE ‘P’

// VALID MESSAGE – No Errors or Warnings
// MSH|^~\&|CAIR IIS4.0.0|CAIR IIS||UATPARENT|20160630||ACK^V04^ACK|TEST001|P|2.5.1||||||||||CAIR IIS|UATPARENTMSA|AA|1791129
function record_result($hl7, $hl7ResponseString, $id) {
	$hl7Response = new Message($hl7ResponseString);
	$msa = $hl7Response->getSegmentsByName('MSA')[0];
	$ackCode = $msa->getAcknowledgementCode();
	if ($ackCode && $ackCode[1] === 'A') {
	    syslog(LOG_INFO, "Recieved ACK from remote");
	}
	else {
	    syslog(LOG_ERR, "Recieved NACK from remote");
	    $err = $hl7Response->getSegmentsByName('ERR')[0];
	    syslog(LOG_ERR, "error message: " . $err->getField(8));
	}

	DB::insert( 'immunizations_cair', [
		'immunization_id' => $id, 
		'submission' => $hl7,
		'response' => $hl7ResponseString, 
		'ackcode' => $ackCode[1] ? $ackCode[1] : ''
	]);
}

function db_init() {
	$result = DB::query("SHOW TABLES LIKE 'immunizations_cair'");
	if (count($result) === 0) {
		// Create the immunizations_cair table
		DB::query(
		"CREATE TABLE `immunizations_cair` (
		`id` bigint(20) NOT NULL AUTO_INCREMENT,
		`immunization_id` bigint(20) DEFAULT NULL,
		`date` datetime DEFAULT CURRENT_TIMESTAMP,
		`submission` text,
		`response` text,
		`ackcode` varchar(10) default null,
		PRIMARY KEY (`id`),
		KEY `immunizations_id` (`immunization_id`),
		CONSTRAINT `immunizations_cair_ibfk_1` FOREIGN KEY (`immunization_id`) REFERENCES `immunizations` (`id`)
		)");
	}
}


function parse_ack() {
}

closelog();
