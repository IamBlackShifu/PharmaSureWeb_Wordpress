<?php
namespace PharmaSure\Core\Security;

use PharmaSure\Core\TenantContext;

final class RateLimiter {
	private const WINDOW = 60;
	private const MAX_BODY = 2097152;
	public static function register(){add_filter('rest_pre_dispatch',array(self::class,'enforce'),10,3);add_filter('rest_post_dispatch',array(self::class,'headers'),10,3);}

	public static function enforce($result,$server,$request){
		$route=(string)$request->get_route();if(!str_starts_with($route,'/pharmasure/v1/')){return $result;}
		$method=strtoupper((string)$request->get_method());$length=absint($_SERVER['CONTENT_LENGTH']??0);
		if(!in_array($method,array('GET','HEAD','OPTIONS'),true)&&$length>self::MAX_BODY){self::security_event('api_request_too_large','A PharmaSure API request exceeded the 2 MB body limit.','warning');return new \WP_Error('request_too_large','Request bodies may not exceed 2 MB.',array('status'=>413));}
		$limit=self::limit($route,$method);$window=(int)(floor(time()/self::WINDOW)*self::WINDOW);$identity=self::identity();$normalized=preg_replace(array('#/\d+(?=/|$)#','#[0-9a-f]{8}-[0-9a-f-]{27,}#i'),array('/{id}','{uuid}'),$route);$hash=hash_hmac('sha256',$identity.'|'.$method.'|'.$normalized,(string)wp_salt('auth'));
		$count=self::increment($hash,$window);if($count<=$limit){return $result;}if($count===$limit+1){self::security_event('api_rate_limit_exceeded','A PharmaSure API request budget was exceeded.','warning');}
		$retry=max(1,$window+self::WINDOW-time());return new \WP_Error('rate_limited','Too many requests. Retry after the current request window.',array('status'=>429,'retry_after'=>$retry,'headers'=>array('Retry-After'=>(string)$retry)));
	}

	public static function headers($response,$server,$request){if(str_starts_with((string)$request->get_route(),'/pharmasure/v1/')){$response->header('Cache-Control','no-store, private');$response->header('X-Content-Type-Options','nosniff');$response->header('Referrer-Policy','no-referrer');$response->header('X-Frame-Options','DENY');}return $response;}
	private static function limit($route,$method){if(str_contains($route,'/offline/mutations')||str_contains($route,'/offline/snapshot')||str_contains($route,'/integrations/callbacks/')){return 120;}if(in_array($method,array('GET','HEAD','OPTIONS'),true)){return 240;}return is_user_logged_in()?90:30;}
	private static function identity(){if(is_user_logged_in()){$tenant=(int)TenantContext::instance()->get_tenant_id();return 'tenant:'.$tenant.':user:'.get_current_user_id();}$ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');return 'anonymous:'.hash_hmac('sha256',$ip,(string)wp_salt('nonce'));}
	private static function increment($hash,$window){global $wpdb;$table=$wpdb->prefix.PHARMASURE_TABLE_PREFIX.'rate_limits';$written=$wpdb->query($wpdb->prepare("INSERT INTO $table (bucket_hash,window_start,request_count,expires_at) VALUES (%s,%d,1,%s) ON DUPLICATE KEY UPDATE request_count=request_count+1",$hash,$window,gmdate('Y-m-d H:i:s',$window+self::WINDOW*2)));if(false===$written){self::security_event('api_rate_limiter_unavailable','The PharmaSure API request budget store was unavailable.','critical');return PHP_INT_MAX;}$count=(int)$wpdb->get_var($wpdb->prepare("SELECT request_count FROM $table WHERE bucket_hash=%s AND window_start=%d",$hash,$window));if(1===random_int(1,100)){$wpdb->query("DELETE FROM $table WHERE expires_at<UTC_TIMESTAMP() LIMIT 500");}return $count;}
	private static function security_event($type,$description,$severity){global $wpdb;$tenant=is_user_logged_in()?(int)TenantContext::instance()->get_tenant_id():0;$wpdb->insert($wpdb->prefix.PHARMASURE_TABLE_PREFIX.'security_events',array('tenant_id'=>$tenant?:null,'user_id'=>get_current_user_id()?:null,'event_type'=>$type,'severity'=>$severity,'description'=>$description,'ip_address'=>sanitize_text_field($_SERVER['REMOTE_ADDR']??'unknown'),'user_agent'=>sanitize_text_field(substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500)),'is_resolved'=>0,'created_at'=>current_time('mysql',true)));}
}
