### OMPDatacite Plugin

#### Introduction
This plugin registers DOIS for monographs, chapters and publication formats for DOI provider [Datacite.org](https://datacite.org).

Current Schema version is [4.4](https://support.datacite.org/docs/datacite-metadata-schema-44)

####  Installation
```bash
OMP=/path/to/OMP_INSTALLATION
cd $OMP/plugins/generic
git clone https://github.com/withanage/datacite

cd $OMP
php lib/pkp/tools/installPluginVersion.php plugins/generic/datacite/version.xml
```

####  Setup Datacite
* Navigate to {OMP_SERVER}/index.php/{MY_PRESS}/management/distribution#dois/doisRegistration
* Choose Registration Agency "Datacite"
* Username  : Username
* Password: Password
* Only for testing: Use the DataCite test prefix, test username and test password for DOI registration. Is test mode enabled, this plugin doesn't change any DOI status and uses the Datacite test api for registration.


####  Credits
Main Developer  
[Dulip Withanage](https://github.com/withanage)  
[Christian Marsilius](https://github.com/nongenti)
