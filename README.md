# openemr_cair_synch
Sync COVID-19 vaccination records between [OpenEMR](http://open-emr.org) and [CAIR](https://cairweb.org/)

This is a tool to satisfy the CAIR reporting requirements for COVID-19 
vaccinations in California where vaccine distributions need to be reported
within 24 hours.  The approach taken here is to send only
the minimum required information and to set the record in CAIR as locked.  This was
tested against OpenEMR 5.0.1.6, is expected to be run from cron, and does
not require integration with OpenEMR core.  A typical CRON job may look like:

```
# We submit immunizattion records to CAIR every 4 hours
0 */4 * * * /usr/bin/php /home/dwilliams/openemr/openemr_cair_synch/sync.php --dbuser=openemr --dbpass=password --dbname=openemr --cairuser=user --cairpass=password --cairfacility=facility --cairregioncode=CAIRBA
```

It is assumed that the VCX codes associated with the COVID-19 vaccines have been
entered into the OpenEMR codes table.  This script will only submit COVID-19
vaccination records. This script will create an additional table in the OpenEMR
database to track vaccination submissions to CAIR called immunizations_cair.

php-xml, and php-soap are php requirements that will need to be satisifed 
before running `composer install` to include the remaining requirements.

See also [Daniel Pflieger's](https://github.com/growlingflea) contributions [[1](https://github.com/growlingflea/openemr/commits/rel-501-CAIR-plug-in),[2](https://github.com/growlingflea/openemr/commits/rel-500-CAIR2-plugin)] for integrating CAIR into OpenEMR.
