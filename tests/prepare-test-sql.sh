#!/bin/sh
#   run this script to duplicate the database structure
#   the duplicate is used to create a test env.

cp ../sql/full.sql ../sql/unittest/001_testtables.sql
sed -i '1s/^/USE unittest;\n/' ../sql/unittest/001_testtables.sql
