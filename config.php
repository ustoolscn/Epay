<?php
/*数据库配置*/
if(!function_exists('epay_env')) {
	function epay_env($key, $default = null) {
		$value = getenv($key);
		return $value === false || $value === '' ? $default : $value;
	}
}

$dbconfig=array(
	'host' => epay_env('DB_HOST', 'localhost'), //数据库服务器
	'port' => (int)epay_env('DB_PORT', 3306), //数据库端口
	'user' => epay_env('DB_USER', ''), //数据库用户名
	'pwd' => epay_env('DB_PASSWORD', ''), //数据库密码
	'dbname' => epay_env('DB_NAME', ''), //数据库名
	'dbqz' => epay_env('DB_PREFIX', 'pay') //数据表前缀
);
