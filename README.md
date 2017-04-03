QuickDNS [![Build Status](https://travis-ci.org/kasperhartwich/quickdns.svg?branch=master)](https://travis-ci.org/kasperhartwich/quickdns)
========

For how to use, take a look at the tests for now. This is work-in-progress.

### TODO
Create records
Delete records
Record model
Add zone to template
Remove zone from template
Add zone to group
Remove zone from group
..more..

### Testing
To test, you need to specify email and password for a account at QuickDNS as environment variables.
You can do so by setting the variables in bash when running phpunit:

`QUICKDNS_EMAIL=email@quickdns.dk QUICKDNS_PASSWORD=your-password vendor/bin/phpunit`


#### Contribute
You are more than welcome to contribute. Just create a pull request.
