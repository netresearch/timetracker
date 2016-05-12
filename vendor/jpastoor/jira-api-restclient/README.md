# Jira Api Rest Client

## Notification regarding inactivity original source
Since pull requests in the original repository do no longer seem to be accepted I took the liberty to merge in the pull requests. 
I will keep it updated with new pull requests until @chobie returns. 

[![Build Status](https://secure.travis-ci.org/chobie/jira-api-restclient.png)](http://travis-ci.org/chobie/jira-api-restclient)

you know JIRA5 supports REST API. this is very useful to make some custom notifications and automated jobs.
(JIRA also supports email notification, but it's too much to custom templates, notification timing. unfortunately it requires Administration permission.)
this API library will help your problems regarding JIRA. hope you enjoy it.

# Usage

composer.json

```
composer require jpastoor/jira-api-restclient 2.0.*
```


````php
<?php
$api = new chobie\Jira\Api(
    "https://your-jira-project.net",
    new chobie\Jira\Api\Authentication\Basic("yourname", "password")
);

$walker = new chobie\Jira\Issues\Walker($api);
$walker->push("project = YOURPROJECT AND (status != closed and status != resolved) ORDER BY priority DESC");
foreach ($walker as $issue) {
    var_dump($issue);
    // send custom notification here.
}
````

# License

MIT License

# JIRA5 Rest API Documents

https://developer.atlassian.com/static/rest/jira/6.0.html
