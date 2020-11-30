<?php
/**
 * Model.php
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

namespace SendyImprovements\Model;

use mysql_xdevapi\Exception;

abstract class Model
{
    protected static $dbConnection;
    private $modelData;

    public static function setDbConnection($dbConnection)
    {
        self::$dbConnection = $dbConnection;
    }

    public static function getDbConnection()
    {
        return self::$dbConnection;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $this->modelData)) {
            return $this->modelData[$name];
        } else {
            throw new \Exception("Field $name does not exist");
        }
    }

    public static function factory($dataArray)
    {
        $className = get_called_class();

        $regexp = '#_(.)#e';

        $model = new $className();

        foreach ($dataArray as $key => $value) {
            $camelCaseKey = preg_replace($regexp, "strtoupper('\\1')", $key);
            $model->modelData[$camelCaseKey] = $value;
        }
        return $model;
    }
}