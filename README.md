SCLog
======

SCLog is an OpenSource logging class intended to be used with the Oracle Service Cloud product. It was developed by ServiceVentures ([servicecloudedge.com](http://servicecloudedge.com)) and is provided completely free as a gift to the Oracle Service Cloud community

### Highlights
- Easy Install 
- Configurable via Service Cloud console
- Easily search and filter log records via reports

### Installation
- Import and deploy the Service Ventures custom object package (/imports/scLog_object.zip)
- Import scProductExtension, and scLog workspaces (located in the imports directory)
- Import scProductExtension, scConfigFilter, scLogFilter, and scLogXrefs report (located in the imports directory)
- Update the imported workspaces to reference the imported reports
  
### Sample Code - PHP #
The PHP version of SCLog is intended to be run from your Customer Portal custom controller or widget:

	require(get_cfg_var('doc_root') . '/cp/customer/development/libraries/SCLog.php');
	$logObj = new \Custom\Libraries\SCLog('A Sample Extension');

	try{
	    //some exception-prone code...
	}
	catch(\Exception $e)
	{
	    $logObj->error('An error occured', $e->getMessage());
	}
    
### Viewing Logs
Log records are saved as SvcVentures.scLog custom objects, the scLog_report definition included in the import folder 
provides basic log-viewing functionality 

    
### License
This add-in was developed by Service Ventures LLC [servicecloudedge.com](http://servicecloudedge.com) and is provided free of charge under an MIT license, without warranty.

### SCLog Pro
Consider upgrading to SCLog Pro to receive: 

- Write logs to the file system and the database
- Web based log file browser
- Automated log pruning
- Installation and development support
 
### ServiceVentures
ServiceVentures is a software company focused on building products to extend Oracle Service Cloud and SalesForce platforms.  
    