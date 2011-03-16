<?php
/**
* A PHP5 library to create, verify and resolve MuseumIDs.
* See <http://museumid.net/documentation> for further information.
*
* This program is free software: you can redistribute it and/or modify
* it under the terms of the GNU General Public License as published by
* the Free Software Foundation, either version 3 of the License, or
* (at your option) any later version.
*
* This program is distributed in the hope that it will be useful,
* but WITHOUT ANY WARRANTY; without even the implied warranty of
* MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
* GNU General Public License for more details.
*
* You should have received a copy of the GNU General Public License
* along with this program.  If not, see <http://www.gnu.org/licenses/>.
* 
* @author Georg Hohmann <dev@museumid.net>
* @version 0.1.110317
* @link <https://github.com/MuseumID/lib>
* @copyright Georg Hohmann 2011
* @license GPLv3 <http://www.gnu.org/licenses/gpl.txt>
*/

/**
* Includes UUID library
* Requires lib.uuid.php (http://jkingweb.ca/code/php/lib.uuid/) >= Version 2010-02-15
*/
require_once('lib.uuid.php');

/**
* MID main class
* @see MNS()
* @see MOI()
* @author Georg Hohmann
*/
class MID {

    protected $dblink = NULL;
    protected $dbconn = NULL;
    protected $dbconf = array();

    /**
    * Constructor which creates a db storage if dbconf is set
    * @param dbconf an optional array with db settings ("host"=>"","name"=>"","user"=>"","pass"=>"","pref"=>"")
    * @author Georg Hohmann
    */
    function __construct($dbconf=NULL) {
        if(!empty($dbconf) && empty($this->dblink) && empty($this->dbconn)) {
            // Connect to database
            $this->dblink = mysql_connect($dbconf['host'],$dbconf['user'],$dbconf['pass']);
            if (!$this->dblink) {
                throw new MIDError('ERROR! Connection to host failed: '.mysql_error());
            }
            $this->dbconn = mysql_select_db($dbconf['name'],$this->dblink);
            if (!$this->dbconn) {
                throw new MIDError('ERROR! Connection to database failed: '.mysql_error());
            }
            $this->dbconf = $dbconf;
            // Create tables if not existing
            $sql[0] = "SET NAMES utf8;";
            $sql[1] = "CREATE TABLE IF NOT EXISTS `".$this->dbconf['pref']."mns` (
                        `mns_uid` char(36) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Museum Namespace UUID',
                        `mns_sld` varchar(50) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Sub-Level-Domains',
                        `mns_tld` varchar(10) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Top-Level-Domain',
                        `mns_src` varchar(20) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'default' COMMENT 'Source',
                        `mns_tms` int(10) NOT NULL COMMENT 'Unix Timestamp',
                        PRIMARY KEY (`mns_uid`),
                        KEY `sld` (`mns_sld`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Museum Namespaces'";
            $sql[2] = "CREATE TABLE IF NOT EXISTS `".$this->dbconf['pref']."moi` (
                        `moi_uid` char(36) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Museum Object Identifier UUID',
                        `moi_ivn` varchar(50) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Inventory Number',
                        `moi_mns` char(36) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Museum Namespace UUID',
                        `moi_src` varchar(20) COLLATE utf8_unicode_ci NOT NULL DEFAULT 'default' COMMENT 'Source',
                        `moi_tms` int(10) NOT NULL COMMENT 'Unix Timestamp',
                        PRIMARY KEY (`moi_uid`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Museum Object Identifiers'";
            $sql[3] = "CREATE TABLE IF NOT EXISTS `".$this->dbconf['pref']."res` (
                        `res_uid` char(36) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Resource UUID',
                        `res_urn` char(36) COLLATE utf8_unicode_ci NOT NULL COMMENT 'MuseumID URN',
                        `res_url` varchar(255) COLLATE utf8_unicode_ci NOT NULL COMMENT 'URL',
                        `res_fmt` varchar(20) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Format',
                        `res_typ` varchar(20) COLLATE utf8_unicode_ci NOT NULL COMMENT 'Type',
                        `res_tms` int(10) NOT NULL COMMENT 'Unix Timestamp',
                        PRIMARY KEY (`res_uid`)
                       ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Resources'";
            foreach($sql as $key => $request) {
                if (!$result = mysql_query($request)) {
                    throw new MIDError('ERROR! Could not create db: '.mysql_error());
                }
            }
        }
    }
    
    /**
    * Creates an MNS object based on the BDN
    * @param bdn a base domain name as a string
    * @return a MNS object
    * @see MNS()
    * @author Georg Hohmann
    */
    public function getMNS($bdn) {
        $bdn = trim($bdn);
        return new MNS($bdn);
    }

    /**
    * Creates an MOI object based on the IVN and the MNS
    * @param ivn an inventory number as a string
    * @param mns a MNS UUID as a string
    * @return a MOI object
    * @see MOI()
    * @author Georg Hohmann
    */
    public function getMOI($ivn,$mns) {
        $ivn = trim($ivn);
        $mns = trim($mns);
        return new MOI($ivn,$mns);
    }
    
    /**
    * Saves a MNS to db
    * @param mns a MNS as a string
    * @param bdn a BDN as a string
    * @param src an optional source definition value as a string
    * @return TRUE on success
    * @author Georg Hohmann
    */
    public function saveMNS($mns,$bdn,$src='default') {
        if ($this->dblink && $this->dbconn) {
            $mns = mysql_real_escape_string($mns,$this->dblink);
            $bdn = mysql_real_escape_string($bdn,$this->dblink);
            $src = mysql_real_escape_string($src,$this->dblink);          
            // Extract Top Level Domain
            $tld = strrchr($bdn,'.');
            $tld = substr($tld,1);
            $sld = str_replace(".".$tld,'',$bdn);
            // Create SQL
            $sql  = "INSERT INTO ".$this->dbconf['pref']."mns (mns_uid,mns_sld,mns_tld,mns_src,mns_tms)";
            $sql .= " VALUES ('".$mns."','".$sld."','".$tld."','".$src."',UNIX_TIMESTAMP())";
            // Insert data in table 'mns'
            $result = mysql_query($sql);
            if (!$result) {
                if(mysql_errno()==1062) {
                    throw new MIDWarning('NOTE@saveMNS: MNS already exists!');
                } else {
                    throw new MIDError('ERROR@saveMNS: Saving MNS to db failed: '.mysql_error());
                }
            }
            return TRUE;
        } else {
            throw new MIDError('ERROR@saveMNS: No db connection available"');
        }
    }

    /**
    * Saves a MOI to db
    * @param moi a MOI UUID as a string
    * @param mns a MNS as a string
    * @param ivn an inventory number as a string
    * @param src an optional source definition value as a string
    * @return TRUE on success
    * @author Georg Hohmann
    */
    public function saveMOI($moi,$mns,$ivn,$src='default') {
        if ($this->dblink && $this->dbconn) {
            $moi = mysql_real_escape_string($moi,$this->dblink);
            $mns = mysql_real_escape_string($mns,$this->dblink);
            $ivn = mysql_real_escape_string($ivn,$this->dblink);
            $src = mysql_real_escape_string($src,$this->dblink);          
            // Create SQL
            $sql  = "INSERT INTO ".$this->dbconf['pref']."moi (moi_uid,moi_ivn,moi_mns,moi_src,moi_tms)";
            $sql .= " VALUES ('".$moi."','".$ivn."','".$mns."','".$src."',UNIX_TIMESTAMP())";
            // Insert data in table 'moi'
            $result = mysql_query($sql);
            if (!$result) {
                if(mysql_errno()==1062) {
                    throw new MIDWarning('NOTE@saveMOI: MOI already exists!');
                } else {
                    throw new MIDError('ERROR@saveMOI: Saving MOI to db failed: '.mysql_error());
                }
            }
            return TRUE;
        } else {
            throw new MIDError('NOTE@saveMOI: No db connection available');
        }
    }

    /**
    * Extracts a BDN from a given URL
    * @param $url a URL as a string
    * @return a BDN as a string
    * @author Georg Hohmann
    */
    public function extractBDN($url) {
        $url = trim($url);
        // Get BDN of URL (Credits: http://corpocrat.com/2009/02/28/php-how-to-get-domain-hostname-from-url/)
        $newdmn = parse_url($url);
        // Add scheme if missing (needed for next step)
        if(empty($newdmn['scheme'])) {
            $dmn = "http://".$url;
        }
        // Remove www
        $nowww = preg_replace('/www\./','',$url);
        // Extract Base Domain Name
        $domain = parse_url($nowww);
        if(!empty($domain["host"])) {
            $bdn = $domain["host"];
        } else {
            $bdn = $domain["path"];
        }
        return $bdn;
    }
        
    /**
    * Validates a BDN
    * @param bdn as a string
    * @return TRUE on success
    * @author Georg Hohmann
    */
    public function validateBDN($bdn) {
        // Check if BDN is a valid domain
        if (!checkdnsrr($bdn,'ANY')) {
            throw new MIDError('ERROR@validateDBN: BDN could not be validated.');
        } else {
            return TRUE;
        }
    }
    
    /**
    * Validates an URN with nss 'mns' or 'mid'
    * @param urn a URN as a string
    * @return TRUE on success
    * @author Georg Hohmann
    */
    public function validateURN($urn) {
        // Check if urn is empty
        if (empty($urn)) {
            throw new MIDError('No URN submitted');
        } else {
            $urn = trim($urn);
            // Check if urn consists of three parts
            $urnparts = explode(":",$urn);
            if (count($urnparts) != 3) {
                throw new MIDError('Submitted value is not a valid URN');
            } else {
                // Check if namespace is supported
                if ($urnparts[1] != "mns" && $urnparts[1] != "moi") {
                    throw new MIDError('Submitted URN namespace identifier '.$urnparts[1].' is not supported');
                } else {
                    // Check if uuid consists of five valid parts
                    $idparts = explode("-",$urnparts[2]);
                    if (isset($idparts[0])) {$strl0 = strlen($idparts[0]);} else {$strl0 = 0;}
                    if (isset($idparts[1])) {$strl1 = strlen($idparts[1]);} else {$strl1 = 0;}
                    if (isset($idparts[2])) {$strl2 = strlen($idparts[2]);} else {$strl2 = 0;}
                    if (isset($idparts[3])) {$strl3 = strlen($idparts[3]);} else {$strl3 = 0;}
                    if (isset($idparts[4])) {$strl4 = strlen($idparts[4]);} else {$strl4 = 0;}
                    if ($strl0 != 8 || $strl1 != 4 || $strl2 != 4 || $strl3 != 4 || $strl4 != 12 || isset($idparts[5])) {
                        throw new MIDError('Submitted URN is not valid');
                    } else {
                        return TRUE;
                    }
                }
            }
        }
    }

    /**
    * Resolves an URN from db
    * @param urn a URN as a string
    * @return a MOI or a MNS object
    * @author Georg Hohmann
    */
    public function resolveURN($urn) {
        if ($this->dblink && $this->dbconn) {
            $urn = mysql_real_escape_string(trim($urn),$this->dblink);
            $urnparts = explode(":",$urn);
            switch ($urnparts[1]) {
                case "mns":
                    $sql  = "SELECT * FROM ".$this->dbconf['pref']."mns ";
                    $sql .= " WHERE mns_uid = '".$urnparts[2]."'";
                    break;
                case "moi":
                    $sql  = "SELECT * FROM ".$this->dbconf['pref']."moi, ".$this->dbconf['pref']."mns";
                    $sql .= " WHERE moi_uid = '".$urnparts[2]."'";
                    $sql .= " AND mns_uid = moi_mns";
                    break;
                default:
                    throw new MIDError('URN is invalid or unsupported');
            }
            // Select
            $result = mysql_query($sql);
            if (!$result) {
                throw new MIDError('ERROR@resolveURN: Resolving URN from db failed: '.mysql_error());
            }
            $numrows = mysql_num_rows($result); 
            if (!$numrows || $numrows<1) {
                throw new MIDError('ERROR@resolveURN: URN not registered.');
            }
            $row = mysql_fetch_assoc($result);
            // Create result object
            switch ($urnparts[1]) {
                case "mns":
                    $bdn = $row['mns_sld'].".".$row['mns_tld'];
                    $mns = new MNS($bdn);
                    $mns->time = $row['mns_tms'];
                    return $mns;
                    break;
                case "moi":
                    $moi = new MOI($row['moi_ivn'],$row['moi_mns']);
                    $moi->time = $row['moi_tms'];
                    return $moi;
                    break;
            }
        } else {
            throw new MIDError('NOTE@resolveURN: No db connection available');
        }
    }

    /**
    * Destructor that closes db connection if present
    * @author Georg Hohmann
    */
    function __destruct() {
        if ($this->dblink) {
            mysql_close($this->dblink);
        }
    }
}

/**
 * MNS Class as child of UUID class
 * @author Georg Hohmann
 */
class MNS extends UUID {

    protected $urn = NULL;
    protected $bdn = NULL;
    protected $time = NULL;
    
    /**
    * Constructor, creates an MNS object
    * @param bdn a BDN as a string
    * @author Georg Hohmann
    */
    function __construct($bdn) {
        // Calling parent constructor for constructing an UUID V5
        parent::__construct(self::mintName(self::SHA1,$bdn,'6ba7b810-9dad-11d1-80b4-00c04fd430c8'));
        // Setting variables
        $this->bdn = $bdn;
        $this->urn = "urn:mns:".$this->__toString();
        $this->time = time();
	}

    /**
    * Gethub that overwrites some functions of the parent gethub
    * @param var a string
    * @author Georg Hohmann
    */
    public function __get($var) {
        switch($var) {
            case "urn":
                return $this->urn;
                break;
            case "bdn":
                return $this->bdn;
                break;
            case "time":
                return $this->time;
                break;
            default:
                return parent::__get($var);
        }
    }
}

/**
 * MOI Class as child of UUID class
 * @author Georg Hohmann
 */
class MOI extends UUID {

    protected $urn = NULL;
    protected $ivn = NULL;
    public $time = NULL;
    protected $mns = NULL;
    protected $mnsurn = NULL;

    /**
    * Constructor, creates an MOI object
    * @param ivn an inventory number as a string
    * @param mns a MNS UUID as a string
    * @author Georg Hohmann
    */
    function __construct($ivn,$mns) {
        // Calling parent constructor for constructing an UUID V5
        parent::__construct(self::mintName(self::SHA1,$ivn,$mns));
        // Setting variables
        $this->ivn = $ivn;
        $this->mns = $mns;
        $this->mnsurn = "urn:mns:".$mns;
        $this->urn = "urn:moi:".$this->__toString();
        $this->time = time();
    }

    /**
    * Gethub that overwrites some functions of the parent gethub
    * @param var a string
    * @author Georg Hohmann
    */
    public function __get($var) {
        switch($var) {
            case "urn":
                return $this->urn;
                break;
            case "ivn":
                return $this->ivn;
                break;
            case "mns":
                return $this->mns;
                break;
            case "mnsurn":
                return $this->mnsurn;
                break;
            case "time":
                return $this->time;
                break;
            default:
                return parent::__get($var);
        }
    }
}

/**
 * MID Error class
 * @author Georg Hohmann
 */
class MIDError extends Exception {
}

/**
 * MID Warning class
 * @author Georg Hohmann
 */
class MIDWarning extends Exception {
}
?>