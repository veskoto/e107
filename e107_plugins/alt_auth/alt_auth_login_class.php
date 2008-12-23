<?php
/*
+ ----------------------------------------------------------------------------+
|     e107 website system
|
|     �Steve Dunstan 2001-2002
|     http://e107.org
|     jalist@e107.org
|
|     Released under the terms and conditions of the
|     GNU General Public License (http://gnu.org).
|
|     $Source: /cvs_backup/e107_0.8/e107_plugins/alt_auth/alt_auth_login_class.php,v $
|     $Revision: 1.6 $
|     $Date: 2008-12-23 20:31:30 $
|     $Author: e107steved $
+----------------------------------------------------------------------------+
*/
define('AA_DEBUG',FALSE);
define('AA_DEBUG1',FALSE);

class alt_login
{
	function alt_login($method, &$username, &$userpass)
	{
		global $pref, $admin_log;
		$newvals=array();
		define("AUTH_SUCCESS", -1);
		define("AUTH_NOUSER", 1);
		define("AUTH_BADPASSWORD", 2);
		define("AUTH_NOCONNECT", 3);
		require_once(e_PLUGIN."alt_auth/".$method."_auth.php");
		$_login = new auth_login;

		if(isset($_login->Available) && ($_login->Available === FALSE))
		{	// Relevant auth method not available (e.g. PHP extension not loaded)
			return AUTH_NOCONNECT;
		}

		$login_result = $_login -> login($username, $userpass, $newvals, FALSE);

		if($login_result === AUTH_SUCCESS )
		{
			if (MAGIC_QUOTES_GPC == FALSE)
			{
				$username = mysql_real_escape_string($username);
			}
			$username = preg_replace("/\sOR\s|\=|\#/", "", $username);
			$username = substr($username, 0, varset($pref['loginname_maxlength'],30));

			$aa_sql = new db;
			$uh = new UserHandler;
			$db_vals = array('user_password' => $aa_sql->escape($uh->HashPassword($userpass,$username)));
			$xFields = array();					// Possible extended user fields
			
			// See if any of the fields need processing before save
			if (isset($_login->copyMethods) && count($_login->copyMethods))
			{
				foreach ($newvals as $k => $v)
				{
					if (isset($_login->copyMethods[$k]))
					{
						$newvals[$k] = $this->translate($_login->copyMethods[$k], $v);
						if (AA_DEBUG1) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth convert",$k.': '.$v.'=>'.$newvals[$k],FALSE,LOG_TO_ROLLING);
					}
				}
			}
			foreach ($newvals as $k => $v)
			{
				if (strpos($k,'x_') === 0)
				{	// Extended field
					$k = substr($k,2);
					$xFields['user_'.$k] = $v;
				}
				else
				{	// Normal user table
					if (strpos($k,'user_' !== 0)) $k = 'user_'.$k;			// translate the field names (but latest handlers don't need translation)
					$db_vals[$k] = $v;
				}
			}
			if (count($xFields))
			{
				$qry = "SELECT u.user_id,u.".implode(',u.',array_keys($db_vals)).", ue.user_extended_id, ue.".implode(',ue.',array_keys($xFields))." FROM `#user` AS u
						LEFT JOIN `#user_extended` AS ue ON ue.user_extended_id = u.user_id
						WHERE u.user_loginname='{$username}' ";
				if (AA_DEBUG) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","Query: {$qry}[!br!]".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
			}
			else
			{
				$qry = "SELECT * FROM `#user` WHERE `user_loginname`='{$username}'";
			}
			if($aa_sql -> db_Select_gen($qry))
			{ // Existing user - get current data, see if any changes
				$row = $aa_sql->db_Fetch(MYSQL_ASSOC);
				foreach ($db_vals as $k => $v)
				{
					if ($row[$k] == $v) unset($db_vals[$k]);
				}
				if (count($db_vals)) 
				{
					$aa_sql->db_UpdateArray('user',$db_vals," WHERE `user_id`=".$row['user_id']);
					if (AA_DEBUG1) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","User data update: ".print_r($db_vals,TRUE),FALSE,LOG_TO_ROLLING);
				}
				foreach ($xFields as $k => $v)
				{
					if ($row[$k] == $v) unset($xFields[$k]);
				}
				if (AA_DEBUG) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","User data read: ".print_r($row,TRUE)."[!br!]".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
				if (AA_DEBUG1) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","User xtnd read: ".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
				if (count($xFields))
				{
					if ($row['user_extended_id'])
					{
						if (AA_DEBUG1) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","User xtnd update: ".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
						$aa_sql->db_UpdateArray('user_extended',$xFields," WHERE `user_extended_id`=".intval($row['user_id']));
					}
					else
					{	// Never been an extended user fields record for this user
						$xFields['user_extended_id'] = $row['user_id'];
						if (AA_DEBUG) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","Write new extended record".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
						$aa_sql->db_Insert('user_extended',$xFields);
					}
				}
			}
			else
			{  // Just add a new user
				if (AA_DEBUG) $admin_log->e_log_event(10,debug_backtrace(),"DEBUG","Alt auth login","Add new user: ".print_r($db_vals,TRUE)."[!br!]".print_r($xFields,TRUE),FALSE,LOG_TO_ROLLING);
				if (!isset($db_vals['user_name'])) $db_vals['user_name'] = $username;
				if (!isset($db_vals['user_loginname'])) $db_vals['user_loginname'] = $username;
				if (!isset($db_vals['user_join'])) $db_vals['user_join'] = time();
				$db_vals['user_class'] = varset($pref['initial_user_classes'],'');
				if (!isset($db_vals['user_signature'])) $db_vals['user_signature'] = '';
				if (!isset($db_vals['user_prefs'])) $db_vals['user_prefs'] = '';
				if (!isset($db_vals['user_perms'])) $db_vals['user_perms'] = '';
				$newID = $aa_sql->db_Insert('user',$db_vals);
				if (($newID !== FALSE) && count($xfields))
				{
					$xFields['user_extended_id'] = $newID;
					$aa_sql->db_Insert('user_extended',$xFields);
				}
			}
			return LOGIN_CONTINUE;
		}
		else
		{	// Failure modes
			switch($login_result)
			{
				case AUTH_NOUSER:
					if(!varset($pref['auth_nouser'],0))
					{
						$username=md5("xx_nouser_xx");
						return LOGIN_ABORT;
					}
					break;
				case AUTH_NOCONNECT:
					if(!varset($pref['auth_noconn']))
					{
						$username=md5("xx_noconn_xx");
						return LOGIN_ABORT;
					}
					break;
				case AUTH_BADPASSWORD:
					$userpass=md5("xx_badpassword_xx");
					return LOGIN_ABORT;					// Not going to magically be able to log in!
					break;
			}
		}
		return LOGIN_ABORT;			// catch-all just in case
	}

	
	// Function to implement copy methods
	function translate($method, $word)
	{
		global $tp;
		switch ($method)
		{
			case 'bool1' :
				switch ($tp->uStrToUpper($word))
				{
					case 'TRUE' : return TRUE;
					case 'FALSE' : return FALSE;
				}
				return $word;
			case 'ucase' :
				return $tp->uStrToUpper($word);
			case 'lcase' :
				return $tp->uStrToLower($word);
			case 'ucfirst' :
				return ucfirst($word);						// TODO: Needs changing to utf-8 function
			case 'ucwords' :
				return ucwords($word);						// TODO: Needs changing to utf-8 function
			case 'none' :
				return $word;
		}
	}

}
?>