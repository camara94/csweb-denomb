# CSPro Sync REST API #

PHP server that implements the server side of data synchronization between devices in the field running CSEntry and a central server.

Detailed API documentation can be found in the file src/AppBundle/CSPro/swagger.json

## Requirements ##
* Apache or IIS with mod_rewrite/URL rewrite
* MySQL 5.5.3+
* PHP 5.5.9+ with the following modules:
	* file_info
	*pdo
	*pdo_mysql
	*curl (or enable set allow_url_fopen in php.ini)
	*openssl


## Setup ##
1. Copy the source code to your www directory (so you have www/csweb)
2. Make sure that the directories *var*, *var/logs*, and *app*  are wri by the web server user
3. Create a MySQL database and a user with read/write access to the database.
4. In a browser go to <yourserverurl>/csweb/setup and follow the setup wizard. 

## Usage ##
Login to the web interface (<yourserverurl>/csweb/) to add users and upload dictionaries.
In your CSPro application in the synchronization options enter the URL of your server (<yourserverurl>/csweb/api) or in your application logic use the syncconnect/syncdata/syncfile functions to upload/download data files to your server.