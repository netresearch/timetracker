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

