#!/bin/sh
#   run this script to duplicate the database structure
#   the duplicate is used to create a test env.
#   command runs on linux and mac

sed '1s/^/USE unittest;\n/' ../sql/full.sql > ../sql/unittest/001_testtables.sql
