<?php
/**
* mysql-convert-latin1-to-utf8.php
*
* Converts incorrect MySQL latin1 columns to UTF8.
* Can be set to output queries only.
*
* NOTE: Look for 'TODO's for things you may need to configure.
*
* Documentation at:
* http://nicj.net/2011/04/17/mysql-converting-an-incorrect-latin1-column-to-utf8
*
* Or, read README.md.
*
* @author Nic Jansma <nic@nicj.net>
* @author Velpi <velpi@groept.be>
* @copyright 2012 Nic Jansma
* @link http://www.nicj.net
*/

// TODO: The collation you want to convert all columns to
$newCollation = 'utf8_unicode_ci';

// TODO: Database information
$dbHost = 'localhost';
$dbName = '';
$dbUser = '';
$dbPass = '';
$echoQueries=true;
$execQueries=false;

// Open a connection to the information_schema database
$infoDB = mysql_connect($dbHost, $dbUser, $dbPass);
mysql_select_db('information_schema', $infoDB);

// Open a second connection to the target (to be converted) database
$targetDB = mysql_connect($dbHost, $dbUser, $dbPass, true);
mysql_select_db($dbName, $targetDB);

//
// TODO: FULLTEXT Indexes
//
// You may need to drop FULLTEXT indexes before the conversion -- execute the drop here.
// eg.
// sqlExec($targetDB, "ALTER TABLE MyTable DROP INDEX `my_index_name`");
//
// If so, you should restore the FULLTEXT index after the conversion -- search for 'TODO'
// later in this script.
//

// Get all tables in the specified database
$tables = sqlObjs($infoDB,
    "SELECT TABLE_NAME, TABLE_COLLATION FROM TABLES WHERE TABLE_SCHEMA = '$dbName'");

foreach ($tables as $table) {
    $tableName = $table->TABLE_NAME;
    $tableCollation = $table->TABLE_COLLATION;

    // Find all columns that aren't of the destination collation
    $cols = sqlObjs($infoDB, "SELECT * FROM COLUMNS WHERE TABLE_SCHEMA = '$dbName' AND TABLE_Name = '$tableName' AND COLLATION_NAME != '$newCollation' AND COLLATION_NAME IS NOT NULL");

    foreach ($cols as $col) {

        // Save current column settings
        $colName = $col->COLUMN_NAME;
        $colCollation = $col->COLLATION_NAME;
        $colType = $col->COLUMN_TYPE;
        $colDataType = $col->DATA_TYPE;
        $colLength = $col->CHARACTER_OCTET_LENGTH;
        $colNull = ($col->IS_NULLABLE === 'NO') ? 'NOT NULL' : '';

        $colDefault = '';
        if ($col->COLUMN_DEFAULT !== null) {
            $colDefault = "DEFAULT '{$col->COLUMN_DEFAULT}'";
        }

        // Determine the target temporary BINARY type
        $tmpDataType = '';
        switch (strtoupper($colDataType)) {
            case 'CHAR':
                $tmpDataType = 'BINARY';
                break;

            case 'VARCHAR':
                $tmpDataType = 'VARBINARY';
                break;

            case 'TINYTEXT':
                $tmpDataType = 'TINYBLOB';
                break;

            case 'TEXT':
                $tmpDataType = 'BLOB';
                break;

            case 'MEDIUMTEXT':
                $tmpDataType = 'MEDIUMBLOB';
                break;

            case 'LONGTEXT':
                $tmpDataType = 'LONGBLOB';
                break;

            default:
                $tmpDataType = '';
                break;
        }

        if ($tmpDataType === '') {
            print "Unknown type! $colDataType\n";
            exit;
        }

        // Change the column definition to the new type
        $tempColType = str_ireplace($colDataType, $tmpDataType, $colType);

        // Convert the column to the temporary BINARY cousin
	if ( $echoQueries==true) {
	        sqlEcho($targetDB, "ALTER TABLE `$dbName`.`$tableName` MODIFY `$colName` $tempColType $colNull");
	}
	if ( $execQueries==true) {
        	sqlExec($targetDB, "ALTER TABLE `$dbName`.`$tableName` MODIFY `$colName` $tempColType $colNull");
	}


        // Convert it back to the original type with the correct collation
	if ( $echoQueries==true) {
        	sqlEcho($targetDB, "ALTER TABLE `$dbName`.`$tableName` MODIFY `$colName` $colType COLLATE $newCollation $colNull $colDefault");
	}
	if ( $execQueries==true) {
		sqlExec($targetDB, "ALTER TABLE `$dbName`.`$tableName` MODIFY `$colName` $colType COLLATE $newCollation $colNull $colDefault");
	}
    }

    if ($tableCollation !== $newCollation) {
        // Modify the default charset for this table
	if ( $echoQueries==true) {
        	sqlEcho($targetDB, "ALTER TABLE `$dbName`.`$tableName` DEFAULT COLLATE $newCollation");
	}
	if ( $execQueries==true) {
        	sqlExec($targetDB, "ALTER TABLE `$dbName`.`$tableName` DEFAULT COLLATE $newCollation");
	}
    }
}

//
// TODO: Restore FULLTEXT indexes here
// eg.
// sqlExec($targetDB, "ALTER TABLE MyTable ADD FULLTEXT KEY `my_index_name` (`mycol1`)");
//

// Set the default collation
if ( $echoQueries==true) {
	sqlEcho($infoDB, "ALTER DATABASE $dbName COLLATE $newCollation");
}
if ( $execQueries==true) {
	sqlExec($infoDB, "ALTER DATABASE $dbName COLLATE $newCollation");
}
// Done!


//
// Functions
//
/**
* Executes the specified SQL
*
* @param object $db Target SQL connection
* @param string $sql SQL to execute
*
* @return SQL result
*/
function sqlEcho($db, $sql)
{
    echo "$sql;\n";
}



/**
* Executes the specified SQL
*
* @param object $db Target SQL connection
* @param string $sql SQL to execute
*
* @return SQL result
*/
function sqlExec($db, $sql)
{
    echo "#exec $sql;\n";
    $res = mysql_query($sql, $db);

    $error = mysql_error($db);
    if ($error !== '') {
        print "!!! ERROR: $error\n";
    }

    return $res;
}

/**
* Gets the SQL back as objects
*
* @param object $db Target SQL connection
* @param string $sql SQL to execute
*
* @return SQL objects
*/
function sqlObjs($db, $sql)
{
    $res = sqlExec($db, $sql);

    $a = array();

    if ($res !== false) {
        while ($obj = mysql_fetch_object($res)) {
            $a[] = $obj;
        }
    }

    return $a;
}

?>
