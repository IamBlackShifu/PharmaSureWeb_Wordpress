<?php
namespace PharmaSure\Core\Security;

final class Hardening {
	public static function register(){RateLimiter::register();add_action('admin_init',array(self::class,'admin_headers'));add_filter('wp_is_application_passwords_available',array(self::class,'application_password_policy'));}
	public static function admin_headers(){if(headers_sent()){return;}header('X-Content-Type-Options: nosniff');header('Referrer-Policy: strict-origin-when-cross-origin');header('Permissions-Policy: camera=(), microphone=(), geolocation=()');}
	public static function application_password_policy($available){return is_ssl()&&$available;}
}
