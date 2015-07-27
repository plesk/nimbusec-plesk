# Nimbusec Website Security Monitor & Abuse Process Automation

This is a plesk extension compatible with versions 12.0 and above. 

## Install
To install the extension you can either use the official Plesk Extension Catalogue or you can package this repository as zip-file and upload it via the Plesk Admin Panel.
As an alternative you can also install it via command line on your plesk host (note: use the zip-file name here).

    $PLESK_INSTALL_DIR/bin/extension -i nimbusec-plesk.zip

## Uninstall
To ninstall the extension you can either remove it via the Plesk Admin Panel  or use the commandline (note: use the plesk extension id here - nimbusec-hoster-integration
    $PLESK_INSTALL_DIR/bin/extension -u nimbusec-hoster-integration

## Features
This plesk extension allows you to:
* Manage credentials to access our API
* Enable/Disable domains on your plesk host to be scanned with nimbusec
* Manage the schedule of the automated scan tasks of nimbusec on your plesk host
