.. header::
   .. image:: doc/netresearch.jpg
      :height: 0.8cm
      :align: left

.. footer::
   .. class:: footertable

   +----------------------------+----------------------------+----------------------------+
   | Stand: xx.xx.xxxx          | .. class:: centeralign     | .. class:: rightalign      |
   |                            |                            |                            |
   |                            | Netresearch GmbH & Co. KG  | ###Page###/###Total###     |
   +----------------------------+----------------------------+----------------------------+

=======================
Netresearch TimeTracker
=======================

.. sectnum::

.. contents:: Table of Contents

Usage
=====

Add worklog entry
-----------------

Click the button **Add Entry**.
Use the keyboard shortcut **a**.

Edit worklog entry
------------------

Just click inside any field of any existing worklog entry.

Delete worklog entry
--------------------

Rightclick on an worklog entry and select **Delete** from context menu.
Use keyboard shortcut **d** to delete focused worklog entry.

Focus
-----

Worklog entry with focus has a yellow background.
Move the focus with keyboard **up** and **down** keys.

User roles
----------

- **DEV** (Developer): is allowed to track times, do bulk entries (if presets where created) and view bar charts in the **Interpretation** tab
- **CLT** (Controller): Includes the role **DEV** and is additionally able export data to csv in the **Controlling** tab
- **PL** (Project leader): Includes the role **CTL** and is additionally able manage customers, projects, user, teams, presets, ticket systems and actiities in **Administration** tab

Install
=======

Fetch a lcoal copy::

    git clone git@github.com:netresearch/timetracker.git

Install vendor libs::

    composer install

Configuration
=============

Proxy
-----

- https://github.com/CircleOfNice/CiRestClientBundle#configuration
- http://php.net/manual/en/function.curl-setopt.php

add the following lines to your app/config/config.yml::

    circle_rest_client:
        curl:
          defaults:
            CURLOPT_PROXY:        "proxy.example.org:8080"
            CURLOPT_PROXYUSERPWD: "user:password"



Using oAuth to transmit worklogs to Jira ticket system
------------------------------------------------------

1. Configure your Jira ticket system

  https://confluence.atlassian.com/display/JIRA044/Configuring+OAuth+Authentication+for+an+Application+Link

2. Enter config params in **parameters.yml**

  - **jira_client_id** - Name of your oAuth client defined in step 1
  - **jira_client_secret** - path to pem file generated in step 1
  - **jira_base_url** - base URL of your Jira ticket system
  - **jira_auth_redirect_route** - Defines where you will be redirected after gaining your jira access token successfully

3. Create a Ticketsystem in timetracker

  - set the type to **JIRA**
  - check the field **timebooking**
  - The the url field has to match the **jira_base_url** in **parameters.yml**
  - The ticket url is used for referencing ticket names to Jira
    "%s" serves is a placeholder for the ticket name in the URL
    (your url might look as the following: https://jira.example.com/browse/%s)
  - The fields login, password, public and private key can be left empty

4. Assign this ticket system to at least one project

5. Start time tracking to this project

  - The timetracker checks if a valid Jira access token is available
  - If this is missing or incorrect the user is going to be forwarded to the Jira ticket system,
    which asks for the permission to grant read / write access to the timetracker.
  - If permitting, the user will receive an access token from Jira.
  - If not, he won't be asked for permission again.
  - With a valid access token the timetracker will add / edit a jira worklog for each entry with a valid ticket name.
  - The permission can be revoked by each user in its settings section in Jira.

Automatically create timetracker user on valid ldap authentication
------------------------------------------------------------------

Per default every timetracker user has to be created manually.
While setting **ldap_create_user** in **parameters.yml** to **true** new users of type **DEV** are going to be created
automatically on a valid ldap authentication. The type can be changed afterwards via the users panel in the administration tab
or directly in the database.
