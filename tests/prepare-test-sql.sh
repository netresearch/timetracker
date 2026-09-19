#!/bin/sh

# Copyright (c) 2026 Netresearch DTT GmbH
# SPDX-License-Identifier: AGPL-3.0-only

#   run this script to duplicate the database structure
#   the duplicate is used to create a test env.
#   command runs on linux and mac

sed '1s/^/USE unittest;\n/' ../sql/full.sql > ../sql/unittest/001_testtables.sql
