Copyright (c) 2013 Nic Jansma
[http://nicj.net](http://nicj.net)

See [nicj.net](http://nicj.net/2011/04/17/mysql-converting-an-incorrect-latin1-column-to-utf8) for a description of the problem
and how this script aids in correcting the issue.

# Introduction

This script automates the conversion of any UTF-8 data stored in MySQL latin1 columns to proper UTF-8 columns.

I've modified [fabio's script](http://www.varesano.net/blog/fabio/latin1%20encoded%20tables%20or%20databases%20utf8%20data%20stored%20convert%20them%20native%20mysql%20utf8%20tables) to automate the conversion for all of the latin1 columns for whatever database you
configure it to look at. It converts the columns first to the proper BINARY cousin, then to utf8\_general\_ci, while
retaining the column lengths, defaults and NULL attributes.

Warning: This script assumes you know you have UTF-8 characters in a latin1 column. Please test your changes before blindly running the script!

Here are the steps you should take to use the script:

# Determine Which Columns Need Updating

If you're like me, you may have a mixture of latin1 and UTF-8 columns in your databases.  Not all of the columns in my
database needed to be updated from latin1 to UTF-8.  For example, some of the tables belonged to other PHP apps on the
server, and I only wanted to update the columns that I knew had to be fixed.  The script will currently convert all of
the tables for the specified database - you could modify the script to change specific tables or columns if you need.

Additionally, the script will only update appropriate text-based columns.  Character sets are only appropriate for some
types of data: CHAR, VARCHAR, TINYTEXT, TEXT, MEDIUMTEXT and LONGTEXT. Other column types such as numeric (INT) and
BLOBs do not have a "character set".

ENUM and SET column types can be converted **only** if all of the enum possibilities only use characters in the 0-127 ASCII
character set.  If you have ENUMs or SETs that satisfy this criteria, look for the relevant `TODO:` in the script.

You can see what character sets your columns are using via the MySQL Administration tool, phpMyAdmin, or even using a
SQL query against the information\_schema:

    mysql> SELECT * FROM COLUMNS WHERE TABLE_SCHEMA = "MyTable" AND COLLATION_NAME LIKE "latin1%";
    ...
    115 rows in set (0.03 sec)

# Test Convert the Columns

You should test all of the changes before committing them to your database.

The first thing to test is that the SQL generated from the conversion script is correct.  To do this, you can dump the structure of your database:

    server> mysqldump --no-data -h localhost -u dbuser -p mydatabase > structure.sql

And import this structure to another test MySQL database:

    server> mysql -u dbuser -p mydatabase_test < structure.sql

Next, run the conversion script (below) against your temporary database:

    server> php -f mysql-convert-latin1-to-utf8.php

The script will spit out "!!! ERROR" statements if a change fails.  If you encounter ERRORs, modifications may be needed based on your requirements.  Some of the common problems are listed in Step 3.

After you run the script against your temporary database, check the information\_schema tables to ensure the conversion was successful:

    mysql> SELECT * FROM COLUMNS WHERE TABLE_SCHEMA = "MyTable";

As long as you see all of your columns in UTF8, you should be all set!

# Problems You May Encounter

Some of the issues you may encounter:

## FULLTEXT indexes

I have several columns with FULLTEXT indexes on them.  The ALTER TABLE to BINARY command for a column that has a FULLTEXT index will cause an error:

    mysql> ALTER TABLE MyTable MODIFY MyColumn BLOB;
    ERROR 1283 (HY000): Column 'MyColumn' cannot be part of FULLTEXT index

The simple solution I came up with was to modify the script to drop the index prior to the conversion, and restore it afterward:

    ALTER TABLE MyTable DROP INDEX `mycolumn_fulltext`

... (convert all columns) ...

    ALTER TABLE MyTable ADD FULLTEXT KEY `mycolumn_fulltext` (`MyColumn`)

There are TODOs listed in the script where you should make these changes.

## Invalid UTF-8 data

Since my database was over 5 years old, it had acquired some cruft over time. I'm not sure exactly how this happened, but some of the columns had data that are not valid UTF-8 encodings, though they were valid latin1 characters. I believe this occurred before I hardened my PHP application to reject non-UTF-8 data, but I'm not sure. I found this out when initially trying to do the conversion:

    mysql> ALTER TABLE MyTable MODIFY MyColumn VARBINARY(3000) NOT NULL DEFAULT '';
    Query OK, 21171 rows affected (0.66 sec)

    mysql> ALTER TABLE MyTable MODIFY MyColumn varchar(3000) CHARACTER SET utf8 COLLATE utf8_general_ci NOT NULL DEFAULT '';
    ERROR 1366 (HY000): Incorrect string value: '\xE2\x80? fl...' for column 'MyColumn' at row 128

What's going on?

At some point, a character sequence that contained invalid UTF-8 characters was entered into the database, and now MySQL refuses to call the column VARCHAR (as UTF-8) because it has these invalid character sequences.

I checked the HTML representation of this column in my PHP website, and sure enough, the garbage shows up there too:

    ?? flown

The ? is the actual character that your browser shows. Not the best user experience, and definitely not the correct character.

I fixed that single row (via phpMyAdmin), and ran the ALTER TABLE MODIFY command again, and hit the same issue, another row. Looks like there is more than a single corrupt row.

I found a good way of rooting out all of the columns that will cause the conversion to fail. If you SELECT CONVERT (MyColumn USING utf8) as a new column, any NULL columns returned are columns that would cause the ALTER TABLE to fail.

For example:

    mysql> SELECT MyID, MyColumn, CONVERT(MyColumn USING utf8)
           FROM MyTable
           WHERE CONVERT(MyColumn USING utf8) IS NULL
    ...
    5 rows in set, 10 warnings (0.05 sec)

This showed me the specific rows that contained invalid UTF-8, so I hand-edited to fix them. You could manually NULL them out using an UPDATE if you're not afraid of losing data. I had to do this for 6 columns out of the 115 columns that were converted.  Only 30 rows in total were corrupt.

You may also want to [use `utf8mb4` instead of `utf8`](https://medium.com/@adamhooper/in-mysql-never-use-utf8-use-utf8mb4-11761243e434) as your collation.  This means you would set `$defaultCollation='utf8mb4_unicode_ci';`.

# Usage

First, read over the script and make sure you understand what it does.  If you don't understand what it's doing, you
probably shouldn't run it.

Next, check all of the `TODO:`s in the script.  You will need to make some changes to get it to work.

At this point, it may take some guts for you to hit the go button on your live database.

    php -f mysql-convert-latin1-to-utf8.php

Personally, I ran the script against a test (empty) database, then a copy of my live data, then a staging server before finally executing it on the live data.

Warning: Please be careful when using the script and test, test, test before committing to it!

# Version History

* v1.0 - 2011-04-17: Initial release
* v1.1 - 2013-01-25: Added possible ENUM support via [patrick-mcdougle](https://github.com/patrick-mcdougle)
* v1.2 - 2013-03-26: Added SET support and the ability to convert from multiple collations, as well as bulk-doing conversion in one statement for quicker changes via [Synchro](https://github.com/Synchro)
* v1.3 - 2017-05-06: Allows for `config.php` separate from script via [bderubinat](https://github.com/bderubinat)

# Credits

Initially based on [fabio's script](http://www.varesano.net/blog/fabio/latin1%20encoded%20tables%20or%20databases%20utf8%20data%20stored%20convert%20them%20native%20mysql%20utf8%20tables).

Modified by Nic Jansma

Contributions by:
* [patrick-mcdougle](https://github.com/patrick-mcdougle)
* [Synchro](https://github.com/Synchro)
