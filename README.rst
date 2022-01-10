.. header::
   .. image:: doc/netresearch.jpg
      :height: 25px
      :align: left

=======================
Netresearch TimeTracker
=======================

Project and customer based time tracking for company employees.

Features:

- Time tracking with autocompletion
   - Bulk entry for sickness or vacation
- Per-user, pre-project and company wide charts
   - Additional statistics via timalytics__
- Administration interface for customers, projects, users and teams
- XLSX export for controlling tasks
- AD/LDAP authentication
- Jira integration: Creates and updates worklog entries in issues


__ https://github.com/netresearch/timalytics



.. sectnum::

.. contents:: Table of Contents

Usage
=====

Add worklog entry
-----------------

Click the button **Add Entry**.
Or use the keyboard shortcut **a**.

Edit worklog entry
------------------

Just click inside any field of any existing worklog entry.

Delete worklog entry
--------------------

Rightclick on an worklog entry and select **Delete** from context menu.
Or use keyboard shortcut **d** to delete focused worklog entry.

Focus
-----

Worklog entry with focus has a yellow background.
Move the focus with keyboard **up** and **down** keys.

User roles
----------

DEV (Developer)
  is allowed to track times, do bulk entries (if presets where created) and view bar charts in the
  **Interpretation** tab

CTL (Controller)
  Includes the role **DEV** and is additionally able export data to csv in the **Controlling** tab

PL (Project leader)
  Includes the role **CTL** and is additionally able manage customers, projects, user, teams, presets,
  ticket systems and activities in **Administration** tab


Installation and set up
=======================

Requirements
------------

- PHP >= 7.2
- MySQL compatible database
- Composer
- For more details see ``composer.json``


Setup - manual from from sources
--------------------------------

#. Fetch a local copy::

   $ git clone git@github.com:netresearch/timetracker.git

#. Create a MySQL database and import ``sql/full.sql`` into it

#. Install dependencies::

   $ composer install

   It will ask you for some configuration options.

   If you want to adjust that later, edit ``app/config/parameters.yml``

   Also copy ``app/config/sentry.yml.dist`` to ``app/config/sentry.yml``,
   and fill the ``dsn`` key if necessary.

#. Make cache and log directory writable::

   $ chmod -R og+w app/cache/ app/logs/

#. For Apache, copy ``web/.htaccess_dev`` to ``web/.htaccess``.

   For nginx, symlink ``web/app_dev.php`` or ``web/app.php`` to ``web/index.php``.

#. Create a virtual host web server entry

   pointing to ``/path/to/timetracker/web/``

#. Open the timetracker URL in your browser. If you see a white page, run::

   $ php app/console assets:install

#. Import test data so that you have a set of data to work and play with::

   $ mysql timetracker < sql/testdata.sql

   Change the username of user `1` to your LDAP username.

#. Login with your LDAP credentials


Setup - with prebuilt docker images
-----------------------------------

#. Create an empty folder
#. Put the provided docker-composer.yml from this git repo in it
#. Put the provided nginx configuration file from this repo into the above created folder
#. Put the provided ``app/config/parameters.yml.dist`` from this repo as ``parameters.yml`` into the above created folder, and ``sentry.yml.dist`` as ``sentry.yml``.
#. Check and adapt the copied confoguration files to your needs
#. Run ``docker-compose up -d``


Trusted proxies
---------------

To work behind a proxy Symfony needs to know which proxies are allowed to trust.

There are two ENV variables which can be set to modify the proxy behavior of the
app:

TRUSTED_PROXY_LIST
  The variable expects a valid JSON encoded list of IPs or IP ranges::

    TRUSTED_PROXY_LIST=["192.0.0.1","10.0.0.0\/8"]

TRUSTED_PROXY_ALL
  The variable expects a boolean 1/0 to indicate if the application
  should handle each address in ``$_SERVER[REMOTE_ADDR]`` as a trusted proxy::

    TRUSTED_PROXY_ALL=1


Configuration
=============

Using OAuth to transmit work logs to Jira ticket system
-------------------------------------------------------

#. Configure your Jira ticket system

   - https://confluence.atlassian.com/display/JIRA044/Configuring+OAuth+Authentication+for+an+Application+Link
   - https://developer.atlassian.com/server/jira/platform/oauth/
   - https://bitbucket.org/atlassian_tutorial/atlassian-oauth-examples

#. Example for Jira 7

   - Create a OpenSSL key pair with private and public pem file::

       $ openssl genrsa -out jira_privatekey.pem 1024
       $ openssl req -newkey rsa:1024 -x509 -key jira_privatekey.pem -out jira_publickey.cer -days 365
       $ openssl pkcs8 -topk8 -nocrypt -in jira_privatekey.pem -out jira_privatekey.pcks8
       $ openssl x509 -pubkey -noout -in jira_publickey.cer  > jira_publickey.pem

   - Open "Application links" page in your Jira: https://jira.example.com/plugins/servlet/applinks/listApplicationLinks
   - "Create new link" with URL pointing to your TimeTracker installation
   - Just click "Continue" if Jira is blaming "no response"
   - Fill out the following form:
      - Application Name:
           timetracker (or chose any other name you like)
      - Application Type:
           Generic Application
      - Ignore the rest and hit "Continue"

   - After new Application is created click on action "edit" (the little pencil at the right to your new application)
      - Select "Incoming Authentication"
      - Consumer Key:
           timetracker (or chose any other name you like)
      - Consumer Name:
           TimeTracker (or chose any other name you like)
      - Public Key:
           Insert here the public key you created above (``jira_publickey.pem``)
      - Click on "Save"

#. Create a ticket system in TimeTracker

   - Set the type to **Jira**
   - Check the field **timebooking**
   - Enter the Base-URL to your Jira ticket system
   - The ticket URL is used for referencing ticket names to Jira
     "%s" serves is a placeholder for the ticket name in the URL
     (your URL might look as the following: https://jira.example.com/browse/%s)
   - The fields login, password, public and private key can be left empty
   - Enter the OAuth consumer key you already entered in Jira (``timetracker``)
   - Enter your private key you created above into OAuth consumer secret field
     (``jira_privatekey.pcks8``).
     Must begin with ``-----BEGIN PRIVATE KEY-----`` (not ``BEGIN RSA``!).

#. Assign this ticket system to at least one project

#. Start time tracking to this project

   - The TimeTracker checks if a valid Jira access token is available
   - If this is missing or incorrect the user is going to be forwarded to the Jira ticket system,
     which asks for the permission to grant read / write access to the TimeTracker.
   - If permitting, the user will receive an access token from Jira.
   - If not, he won't be asked for permission again.
   - With a valid access token the TimeTracker will add / edit a Jira work log for each entry with a valid
     ticket name.
   - The permission can be revoked by each user in its settings section in Jira.

Automatically create TimeTracker user on valid LDAP authentication
------------------------------------------------------------------

Per default every TimeTracker user has to be created manually.
While setting **ldap_create_user** in **app/config/parameters.yml** to **true** new users of type **DEV** are going
to be created automatically on a valid LDAP authentication. The type can be changed afterwards via the
users panel in the administration tab or directly in the database.


Track time for external ticket systems to internal ticket system
----------------------------------------------------------------

Sometimes you not only want to track the times for the tickets from your ticket system.
Assuming that you have a client providing an own ticket system, but you want to track the times
for work on this tickets into your ticket system.

Example:

* Your client provides tickets to your team via an own ticker system
* The ticket numbers may be ``EXTERNAL-1``, ``EXTERNAL-200`` etc.
* You share the information regarding the progress of the ticket in the clients ticket system
* But you want to track the working time in your internal ticket system instance
* Normally you would need to create a ticket in your ticket system e.g. name ``INTERNAL-1``
* You then would be able to book you efforts to ``INTERNAL-1`` via Timetracker
* That's quite ineffective because you always need to create an internal ticket

This feature tries to solve that problem.

#. Create a project in Jira where the external times should be applied to

   #. Create a Jira project the Timetracker user has access to
   #. Ensure that the project provides the issue type ``task``
   #. Let's assume it is named ``Customer Project`` with the key ``INTERNAL``

#. Create the client's ticket system in TimeTracker

   #. Go to ``Administration > Ticket-Sytem`` and create a new one:

      Name:
        e.g. ``Customer ticket system``

      Type:
        ``Jira`` or ``Other`` or what you like

        The type does not effect this feature in any way

      URL:
        e.g. ``https://ticketing.customer.org/%s``

        This is used to generate links in the work log description

      Timebooking:
        No

        This disables any contact to external ticket system

   #. Save the entry

#. Create the external project in TimeTracker

   #. Go to ``Administration > Projects`` and create a new one:

      Name:
        set to e.g. ``Customer Project``

      Ticket-System:
        Select the above created ``Customer ticket system``

      Ticket-Prefix:
        Enter the prefix of your customers project tickets e.g. ``EXTERNAL`` if the tickets are in the form
        ``EXTERNAL-123``

      Active:
        Yes

      "Internal Jira project key":
        select ``INTERNAL``

      "Internal Jira ticket system":
        select your internal ticket system

If everything is correct, the following will happen:

* If you are booking some working time to e.g. ``EXTERNAL-1`` in TimeTracker for project ``Customer Project``
* TimeTracker will reach out for the configured internal Jira instance
* It will search for an issue which name/summary starts with ``EXTERNAL-1`` in the configured internal Jira project
* If it finds an entry, the work log is applied to this entry
* If it does not find an entry, TimeTracker will create a new internal ticket with name ``EXTERNAL-1``
* The link to the ticket in customer Jira will be applied as ticket description
* The work log is applied to the newly created ticket


Extras
======

JIRA cloud integration
----------------------

.. image:: doc/images/jira-cloud-1.png
   :alt: Jira cloud: Button "Zeiten aus Timetracker laden"


.. image:: doc/images/jira-cloud-2.png
   :alt: Jira cloud displaying times


It is possible to show the timetracker times in Jira cloud,
even when not syncing the times into such an instance.

To get it working, install the `Greasemonkey browser extension`__ and import
the ``scripts/timeSummaryForJira`` script.

Then visit a ticket detail page of a cloud-hosted Jira instance.
The right sidebar will show a "Zeiten aus Timetracker laden" button.
When it has been clicked, the statistics are fetched from the Timetracker API
and displayed there.

__ https://addons.mozilla.org/de/firefox/addon/greasemonkey/


API documentation
=================
The timetracker API is documented in ``web/api.yml`` (OpenAPI v3).
