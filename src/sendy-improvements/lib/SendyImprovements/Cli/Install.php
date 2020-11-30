<?php
/**
 * Install.php
 *
 * LICENSE
 *
 * Copyright 2020 Mighty Technologies LLC
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy,
 * modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE
 * WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR
 * COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE,
 * ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 *
 * @package     sendy-improvements
 * @category    sendy-improvements-core
 * @copyright   Copyright (c) 2020 Mighty Technologies LLC (www.amightygirl.com)
 * @license     https://opensource.org/licenses/MIT MIT License
 */
namespace SendyImprovements\Cli;

class Install extends Cli
{
    const SQL_DIRECTORY = '/sendy-improvements/sql';
    const NO_LOCK = true;

    protected $dbConnection;

    public function run()
    {
        echo "Beginning installation\n";
        $this->installDb();
        echo "Installation complete\n";
    }

    protected function getOpts()
    {
        $options = getopt("h", array('help'));

        if (isset($options['h']) || isset($options['help'])) {
            $this->usage();
            exit;
        }
    }

    protected function usage()
    {
        echo "Usage:\n";
        echo " php install.php [options]\n";
        echo "Options:\n";
        echo " -h, --help   Show this help text\n";
    }

    /**
     * Return the database connection, creating a connection if necessary
     *
     * @return \mysqli
     */
    protected function getDbConnection()
    {
        if ($this->dbConnection !== null) {
            return $this->dbConnection;
        }

        $config = $this->getConfig();
        $dbConfig = $config['database'];

        // Attempt to connect to database server
        if ($dbConfig['port']) {
            $connection = new \mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database'], $dbConfig['port']);
        } else {
            $connection = new \mysqli($dbConfig['host'], $dbConfig['username'], $dbConfig['password'], $dbConfig['database']);
        }

        // If connection failed...
        if ($connection->connect_error) {
            echo "Unable to connect. Installation failed.\n";
            exit;
        }

        mysqli_set_charset($connection, $dbConfig['charset']);

        $this->dbConnection = $connection;
        return $connection;
    }

    protected function installDb()
    {
        echo "Updating database...\n";
        $sqlFiles = glob(SENDY_ROOT . self::SQL_DIRECTORY . '/*.sql');

        foreach ($sqlFiles as $sqlFile) {
            $sql = file_get_contents($sqlFile);
            $this->getDbConnection()->query($sql);
        }
    }




}

