.. header::
   .. image:: doc/netresearch.jpg
      :height: 50px
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
- CSV export for controlling tasks
- LDAP authentication
- JIRA integration: Creates and updates worklog entries in issues


__ https://github.com/netresearch/timalytics



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

DEV (Developer)
  is allowed to track times, do bulk entries (if presets where created) and view bar charts in the
  **Interpretation** tab

CTL (Controller)
  Includes the role **DEV** and is additionally able export data to csv in the **Controlling** tab

PL (Project leader)
  Includes the role **CTL** and is additionally able manage customers, projects, user, teams, presets,
  ticket systems and activities in **Administration** tab


Installation
============

Requirements
------------
- PHP 5.6+
- MySQL database
- composer
- libraries listed in ``composer.json``


Setup
-----

#. Fetch a local copy::

     $ git clone git@github.com:netresearch/timetracker.git

#. Create a MySQL database and import ``sql/schema.sql`` into it
#. Install dependencies::

     $ composer install

   It will ask you for some configuration options.
   If you want to adjust that later, edit ``app/config/parameters.yml``

#. Make cache and log directory writable::

     $ chmod -R og+w app/cache/ app/logs/

#. Copy ``web/.htaccess_dev`` to ``web/.htaccess``.

   On nginx, symlink ``web/app_dev.php`` or ``web/app.php``
   to ``web/index.php``.
#. Create a virtual host web server entry
   pointing to ``/path/to/timetracker/web/``
#. Open the timetracker URL in your browser. If you see a white page, run::

     $ php app/console assets:install
#. Login with your LDAP credentials


Configuration
=============

Using OAuth to transmit work logs to Jira ticket system
-------------------------------------------------------

#. Configure your Jira ticket system

   - https://confluence.atlassian.com/display/JIRA044/Configuring+OAuth+Authentication+for+an+Application+Link
   - https://developer.atlassian.com/server/jira/platform/oauth/
   - https://bitbucket.org/atlassian_tutorial/atlassian-oauth-examples

#.#. Example for Jira 7

   - Create a SSH key pair with private and public pem file
   - Open "Application links" page in your Jira: https://jira.example.com/plugins/servlet/applinks/listApplicationLinks
   - "Create new link" with URL pointing to your TimeTracker installation
   - Just click "Continue" if Jira is blaming "no response"
   - Fill out the following form:
     - Application Name: timetracker (or chose any other name you like)
     - Application Type: Generic Application
     - Ignore the rest and hit "Continue"
   - After new Application is created click on action "edit" (the little pencil at the right to your new application)
     - Select "Incoming Authentication"
     - Consumer Key: timetracker (or chose any other name you like)
     - Consumer Name: TimeTracker (or chose any other name you like)
     - Public Key: insert here the public key you created above
     - Click on "Save"

#. Create a ticket system in TimeTracker

   - Set the type to **Jira**
   - Check the field **timebooking**
   - Enter the Base-URL to your Jira ticket system
   - The ticket URL is used for referencing ticket names to Jira
     "%s" serves is a placeholder for the ticket name in the URL
     (your URL might look as the following: https://jira.example.com/browse/%s)
   - The fields login, password, public and private key can be left empty
   - Enter the OAuth consumer key you already entered in Jira
   - Enter your private key you created above into OAuth consumer secret field

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
