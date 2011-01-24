<?

/**
*  Collection of functions to manage users
* 
*  @author Federico Ulfo <rainelemental@gmail.com> | www.federicoulfo.it
*  @copyright RainFramework is under GNU/LGPL 3 License
*  @link http://www.rainframework.com
*  @version 2.0
*  @package RainFramework
*/




/**
 * Log the user and return the login level: LOGIN_LOGOUT, LOGIN_WAIT, LOGIN_ERROR, LOGIN_BANNED, LOGIN_NOT_LOGGED, LOGIN_DONE, LOGIN_LOGGED
 * If enable_cookies it save login/pw (crypted md5($salt.$password) to log automatically
 *
 * @param string $login
 * @param string $password
 * @param bool $enable_cookies True se vuoi salvare nei cookie login e password
 * @param bool $logout True per fare il logout dell'utente
 * @param integer $errorWait Se il login e' errato la pagina va in sleep per $errorWait secondi
 * @param int $logout
 */
function login( $login = null, $password = null, $enable_cookies = false, $logout = null, $errorWait = 5 ){

	if( $logout )
		return LOGIN_LOGOUT;

	// true if the user is logged
	elseif( !$login && !$password && isset( $_SESSION['user'] ) && isset( $_SESSION['user']['check'] ) && $_SESSION[ 'user' ]['check'] == SITE_DIR ){
		$GLOBALS['user'] = $_SESSION[ 'user' ];
		return LOGIN_LOGGED;
	}
	else
		$_SESSION['user'] = null;

	//se login e password sono salvate nei cookie
	if( isset($_COOKIE['login']) AND isset($_COOKIE['password']) ){
		$login = $_COOKIE['login'];
		$salt_and_pw = $_COOKIE['password'];
	}
	else
		$salt_and_pw = null;
	

	//check if there's login and pw, or salt_pw
	if( $login AND ($password OR $salt_and_pw) ){

		$db = new MySql();
		if( !$salt_and_pw )
			$salt_and_pw = md5( $db->getField( "salt", "SELECT salt FROM ".DB_PREFIX."user WHERE email = '{$login}'" ) . $password );

		if( $user = $db->getRow( "SELECT * 
								  FROM ".DB_PREFIX."user
								  WHERE email = '$login' AND password = '$salt_and_pw'" ) ){

			// create new salt and password
			if( $password ){
				$user_id = $user['user_id'];
				$salt=rand( 0, 99999 );
				$md5_password = md5( $salt . $password );
				$db->query( "UPDATE ".DB_PREFIX."user SET password='$md5_password', salt='$salt', activation_code='' WHERE user_id='$user_id'" );	
			}
		
			if( $enable_cookies ){
				setCookie( "login", $login, time( ) + $one_year = 60*60*24*31*12 );
				setCookie( "password", $salt_and_pw, time( ) + $one_year );
			}
			
			$user['check'] = $_SESSION[ 'user' ]['check'] = SITE_DIR;

			//salvo i dati dell'utente
			$GLOBALS['user'] = $_SESSION['user'] = $user;

			//update date and IP
			$db->query( "UPDATE ".DB_PREFIX."user SET last_ip='".IP."', data_login=UNIX_TIMESTAMP() WHERE user_id='{$user['user_id']}'" );

			return LOGIN_DONE;
		}
		else{

			// if login is wrong PHP will sleep for $errorWait seconds
			sleep( $errorWait );
			
			unset( $GLOBALS['user'] );
			unset($_SESSION['user']);
			setcookie ("login", "", time() - 3600);
			setcookie ("password", "", time() - 3600);

			return LOGIN_ERROR;
		}
	}
	else
		return LOGIN_NOT_LOGGED;
	
}

/**
 * Logout
 */
function logout( ){

	if( $user_id = getUserId() )
		userWhereIsLogout( $user_id );
	unset($GLOBALS['user']);
	unset($_SESSION['user']);
	setcookie ("login", "", time() - 3600);
	setcookie ("password", "", time() - 3600);
}


function getUserId( ){
	if( isset( $_SESSION[ 'user' ] ) )
		return isset( $_SESSION[ 'user' ]['user_id'] ) ? $_SESSION[ 'user' ]['user_id'] : NULL;
}



function refreshUserInfo( ){
	$db = new MySql();
	$GLOBALS['user'] = $_SESSION['user'] = getUser();
	$GLOBALS['user']['check'] = $_SESSION[ 'user' ]['check'] = SITE_DIR;
	return $GLOBALS['user'];
}



function getUser( $user_id = NULL ){
	if( $user_id ){
		$db = new MySql();
		$user = $db->getRow( "SELECT * FROM ".DB_PREFIX."user WHERE user_id = '{$user_id}'" );

		global $user_level;
		$user['level'] = constant( "_" . $user_level[ $user['status'] ] . "_" );

		return $user;
	}
	else
		return isset( $_SESSION['user'] ) ? $_SESSION['user'] : null;
}

function isAdmin( $user_id = NULL ){
	return getUserField( "status", $user_id ) >= USER_ADMIN;
}

function isSuperAdmin( $user_id = NULL ){
	return getUserField( "status", $user_id ) >= USER_SUPER_ADMIN;
}

function getUserField( $field, $user_id = NULL ){
	if( $user = getUser( $user_id ) ){
		if( isset( $user[$field] ) )
			return $user[$field];
		else
			trigger_error( "Field not found: $field" );
	}
}

function getUserById( $user_id ){
	$db = new MySql();
	return $db->getRow( "SELECT * FROM ".DB_PREFIX."user WHERE user_id = '$user_id'" );
}


function setUserLang( $lang_id ){
	if( $user_id=getUserId() ){
		$db = new MySql();
		$db->query( "UPDATE ".DB_PREFIX."user SET lang_id='{$lang_id}' WHERE user_id={$user_id}" );
		$_SESSION['user']['lang_id']=$lang_id;
	}
}

function getGroup( $group_id ){
	$db = new MySql();
	return $db->getRow( "SELECT * FROM ".DB_PREFIX."usergroup WHERE group_id='{$group_id}'" );
}

function getGroupList( ){
	$db = new MySql();
	return $db->getArrayRow("SELECT * FROM ".DB_PREFIX."usergroup ORDER BY name","group_id" );
}

function getUserInGroup( $group_id, $order_by = "name", $order = "asc", $limit = 0 ){
	$db = new MySql();
	return $db->getArrayRow( "SELECT * FROM ".DB_PREFIX."usergroup_user INNER JOIN ".DB_PREFIX."user ON ".DB_PREFIX."usergroup_user.user_id = ".DB_PREFIX."user.user_id WHERE ".DB_PREFIX."usergroup_user.group_id = $group_id ORDER BY $order_by $order" . ($limit>0? " LIMIT $limit" : null ) );
}


/**
 * User where is
 */
function userWhereIsInit( $id, $link, $online_time = USER_ONLINE_TIME ){
	$db = new MySql();
	$file 		= basename( $_SERVER['PHP_SELF'] );
	$url 		= $_SERVER['REQUEST_URI']; 
	$where_is 	= isset( $_SESSION['where_is'] ) ? $_SESSION['where_is'] : null;
	$sid 		= session_id();
	$browser	= BROWSER . " " . BROWSER_VERSION;
	$os			= BROWSER_OS;
	$ip 		= IP;

	if( !$where_is ){
		$time = TIME - HOUR;
		$db->query( "DELETE FROM ".DB_PREFIX."user_where_is WHERE time < " . HOUR );
	}

	$user_where_is_id = $where_is ? $_SESSION['where_is']['user_where_is_id'] : $db->getField( "user_where_is_id", "SELECT user_where_is_id FROM ".DB_PREFIX."user_where_is WHERE sid='$sid'" );

	if( $user_id = getUserId() ){
		$guest_id = 0;
		$name = getUserField( "name" );
	}
	else{
		$guest_id = isset( $where_is['guest_id'] ) ? $where_is['guest_id'] : ( 1 + $db->getField( "guest_id", "SELECT guest_id FROM ".DB_PREFIX."user_where_is ORDER BY guest_id DESC LIMIT 1;" ) );
		$name = _GUEST_ . " " . $guest_id;
	}		

	if( $user_where_is_id )
		$db->query( "UPDATE ".DB_PREFIX."user_where_is SET ip='$ip', user_id='$user_id', name='$name', url='$url', id='$id', file='$file', time='".TIME."', sid='$sid' WHERE user_where_is_id='$user_where_is_id'" );
	else{

		//$ip = '70.23.214.189';
		if( !($location = ip2location( $ip, $type = 'array' )) )
			$location = array( 'CountryCode'=>null, 'CountryName'=>null, 'RegionCode'=>null, 'RegionName'=>null, 'City'=>null, 'ZipPostalCode'=>null, 'Latitude'=>null, 'Longitude'=>null, 'TimezoneName'=>null, 'Gmtoffset'=>null );

		replace_sql_injection( $location );

		$db->query( "INSERT INTO ".DB_PREFIX."user_where_is 
					(ip,sid,user_id,guest_id,name,url,id,file,os,browser,time,time_first_click,country_code,country_name,region_code,region_name,city_name,zip,latitude,longitude,timezone_name,gmt_offset)
					VALUES 
					('$ip','$sid','$user_id','$guest_id','$name','$url','$id','$file','$os','$browser', ".TIME.", ".TIME.", '{$location['CountryCode']}', '{$location['CountryName']}', '{$location['RegionCode']}', '{$location['RegionName']}','{$location['City']}', '{$location['ZipPostalCode']}', '{$location['Latitude']}', '{$location['Longitude']}', '{$location['TimezoneName']}', '{$location['Gmtoffset']}')" );
					$user_where_is_id = $db->getInsertedId();
	}

	$_SESSION['where_is'] = array( 'user_where_is_id' => $user_where_is_id, 'id' => $id, 'guest_id'=>$guest_id, 'name'=>$name, 'time' => TIME, 'file' => $file, 'user_id' => $user_id, 'os' => $os, 'browser' => $browser );
}

function userWhereIsRefresh(){
	$db = new MySql();
	if( isset( $_SESSION['where_is'] ) ){
		$db->query( "UPDATE ".DB_PREFIX."user_where_is SET time='".TIME."' WHERE user_where_is_id='{$_SESSION['where_is']['user_where_is_id']}'" );
		$_SESSION['where_is']['time'] = TIME;
	}
}

function getUserWhereIsUser( $user_where_is_id, $online_time = USER_ONLINE_TIME ){
	$db = new MySql();
	return $db->getRow( "SELECT ".DB_PREFIX."user.*, ".DB_PREFIX."user_where_is.*
						FROM ".DB_PREFIX."user_where_is
						LEFT JOIN ".DB_PREFIX."user ON ".DB_PREFIX."user_where_is.user_id = ".DB_PREFIX."user.user_id
						WHERE ( ".TIME." - time ) < $online_time
						AND user_where_is_id = $user_where_is_id");
}

// online time 10 minutes
function getUserWhereIsList( $id = null, $yourself = true, $online_time = USER_ONLINE_TIME ){
	$db = new MySql();
	return $db->getArrayRow( 	"SELECT ".DB_PREFIX."user.*, ".DB_PREFIX."user_where_is.*, IF (".DB_PREFIX."user.user_id > 0, ".DB_PREFIX."user.name, ".DB_PREFIX."user_where_is.name ) AS name
								FROM ".DB_PREFIX."user_where_is
								LEFT JOIN ".DB_PREFIX."user ON ".DB_PREFIX."user_where_is.user_id = ".DB_PREFIX."user.user_id
								WHERE ( ".TIME." - time ) < $online_time
								" . ( $id!=null ? "AND ".DB_PREFIX."user_where_is.id = $id" : null )
								. ( !$yourself ? " AND ".DB_PREFIX."user_where_is.sid != '".session_id()."'" : null )
								);
}

function getUserWhereIs( ){
	return $where_is = isset( $_SESSION['where_is'] ) ? $_SESSION['where_is'] : null;
}


function userWhereIsLogout( $user_id ){
	$db = new MySql();
	$db->query( "DELETE FROM ".DB_PREFIX."user_where_is WHERE user_id='$user_id'" );
	unset( $_SESSION['where_is'] );
}

?>