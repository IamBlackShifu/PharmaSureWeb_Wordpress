<?php
namespace PharmaSure\Offline\Services;

final class OperationsService {
	private $db;
	private $p;
	public function __construct(){global $wpdb;$this->db=$wpdb;$this->p=$wpdb->prefix.'ps_';}

	public function mutations($tenant,$branch=0,$status='',$limit=50){
		$where='m.tenant_id=%d';$args=array(absint($tenant));
		if($branch){$where.=' AND m.branch_id=%d';$args[]=absint($branch);}
		$allowed=array('requires_online_replay','retry','processing','conflict','applied','discarded');
		if(in_array($status,$allowed,true)){$where.=' AND m.status=%s';$args[]=$status;}
		$args[]=min(200,max(1,absint($limit)));
		$sql="SELECT m.id,m.branch_id,m.device_id,m.user_id,m.client_mutation_id,m.mutation_type,m.status,m.attempt_count,m.conflict_code,m.server_reference_type,m.server_reference_id,m.received_at,m.processed_at,d.device_name,d.client_id FROM {$this->p}offline_mutations m LEFT JOIN {$this->p}offline_devices d ON d.id=m.device_id WHERE $where ORDER BY m.id DESC LIMIT %d";
		return $this->db->get_results($this->db->prepare($sql,...$args),ARRAY_A);
	}

	public function mutation($tenant,$id){
		$row=$this->db->get_row($this->db->prepare("SELECT m.*,d.device_name,d.client_id,d.status device_status FROM {$this->p}offline_mutations m LEFT JOIN {$this->p}offline_devices d ON d.id=m.device_id WHERE m.tenant_id=%d AND m.id=%d",absint($tenant),absint($id)),ARRAY_A);
		if(!$row){return new \WP_Error('mutation_not_found','Mutation not found.',array('status'=>404));}
		$row['payload']=json_decode($row['payload'],true);
		return $row;
	}

	public function devices($tenant,$branch=0){
		$where='d.tenant_id=%d';$args=array(absint($tenant));if($branch){$where.=' AND d.branch_id=%d';$args[]=absint($branch);}
		$sql="SELECT d.id,d.branch_id,d.user_id,d.client_id,d.device_name,d.secret_fingerprint,d.status,d.last_seen_at,d.expires_at,d.revoked_at,d.created_at,SUM(CASE WHEN m.status IN ('requires_online_replay','retry','processing') THEN 1 ELSE 0 END) pending_count,SUM(CASE WHEN m.status='conflict' THEN 1 ELSE 0 END) conflict_count FROM {$this->p}offline_devices d LEFT JOIN {$this->p}offline_mutations m ON m.device_id=d.id WHERE $where GROUP BY d.id ORDER BY d.id DESC";
		return $this->db->get_results($this->db->prepare($sql,...$args),ARRAY_A);
	}

	public function monitor($tenant,$branch=0){
		$where='tenant_id=%d';$args=array(absint($tenant));if($branch){$where.=' AND branch_id=%d';$args[]=absint($branch);}
		$rows=$this->db->get_results($this->db->prepare("SELECT status,COUNT(*) total,MIN(received_at) oldest FROM {$this->p}offline_mutations WHERE $where GROUP BY status",...$args),ARRAY_A);
		$counts=array();$oldest=null;foreach($rows as $row){$counts[$row['status']]=(int)$row['total'];if(in_array($row['status'],array('requires_online_replay','retry','processing'),true)&&(!$oldest||$row['oldest']<$oldest)){$oldest=$row['oldest'];}}
		$alerts=$this->db->get_results($this->db->prepare("SELECT id,event_type,severity,description,ip_address,is_resolved,created_at FROM {$this->p}security_events WHERE tenant_id=%d AND event_type IN ('offline_device_auth_failed','offline_signature_failure','offline_replay_attempt','offline_replay_failure') ORDER BY id DESC LIMIT 50",absint($tenant)),ARRAY_A);
		return array('counts'=>$counts,'oldest_pending_at'=>$oldest,'devices'=>$this->devices($tenant,$branch),'security_alerts'=>$alerts);
	}

	public function discard($tenant,$id,$actor,$reason){
		$reason=sanitize_text_field($reason);if(!$reason){return new \WP_Error('reason_required','A manager reason is required.',array('status'=>422));}
		$m=$this->mutation($tenant,$id);if(is_wp_error($m)){return $m;}if(!in_array($m['status'],array('conflict','retry','requires_online_replay'),true)){return new \WP_Error('unsafe_discard','Only queued, retrying or conflicted mutations may be discarded.',array('status'=>409));}
		$ok=$this->db->update($this->p.'offline_mutations',array('status'=>'discarded','next_attempt_at'=>null,'conflict_code'=>'manager_discarded','conflict_details'=>$reason,'processed_at'=>current_time('mysql',true)),array('id'=>absint($id),'tenant_id'=>absint($tenant),'status'=>$m['status']));
		if(1!==$ok){return new \WP_Error('mutation_changed','Mutation state changed; refresh before acting.',array('status'=>409));}
		$this->audit($tenant,$actor,'offline.mutation_discarded',$id,$m,$reason);return array('id'=>absint($id),'status'=>'discarded');
	}

	public function rebase($tenant,$id,$actor,$reason){
		$reason=sanitize_text_field($reason);if(!$reason){return new \WP_Error('reason_required','A manager reason is required.',array('status'=>422));}
		$m=$this->mutation($tenant,$id);if(is_wp_error($m)){return $m;}if('conflict'!==$m['status']){return new \WP_Error('unsafe_rebase','Only conflicted mutations may be rebased.',array('status'=>409));}
		$d=$this->db->get_row($this->db->prepare("SELECT * FROM {$this->p}offline_devices WHERE id=%d AND tenant_id=%d AND branch_id=%d",$m['device_id'],$tenant,$m['branch_id']),ARRAY_A);if(!$d){return new \WP_Error('device_not_found','The originating device is unavailable.',array('status'=>409));}
		$snapshot=(new ReplayService())->snapshot($d,$m['mutation_type'],(array)$m['payload']);if(is_wp_error($snapshot)){return $snapshot;}
		$ok=$this->db->update($this->p.'offline_mutations',array('base_version'=>$snapshot['base_version'],'status'=>'requires_online_replay','attempt_count'=>0,'next_attempt_at'=>null,'conflict_code'=>null,'conflict_details'=>null,'processed_at'=>null),array('id'=>absint($id),'tenant_id'=>absint($tenant),'status'=>'conflict'));
		if(1!==$ok){return new \WP_Error('mutation_changed','Mutation state changed; refresh before acting.',array('status'=>409));}
		$this->audit($tenant,$actor,'offline.mutation_rebased',$id,$m,$reason);return array('id'=>absint($id),'status'=>'requires_online_replay','base_version'=>$snapshot['base_version']);
	}

	public function replay($tenant,$id,$actor){
		$m=$this->mutation($tenant,$id);if(is_wp_error($m)){return $m;}if(!in_array($m['status'],array('retry','requires_online_replay'),true)){return new \WP_Error('mutation_not_replayable','Only queued or retrying mutations may be replayed.',array('status'=>409));}
		$result=(new ReplayService())->replay(absint($id));$outcome=is_wp_error($result)?$result->get_error_code():($result['status']??'unknown');$this->audit($tenant,$actor,'offline.mutation_replay_requested',$id,$m,'Outcome: '.sanitize_key($outcome));return $result;
	}

	private function audit($tenant,$actor,$action,$id,$m,$reason){do_action('pharmasure_audit_log',array('tenant_id'=>absint($tenant),'actor_id'=>absint($actor),'action'=>$action,'object_type'=>'offline_mutation','object_id'=>absint($id),'details'=>array('branch_id'=>$m['branch_id'],'mutation_type'=>$m['mutation_type'],'reason'=>$reason)));}
}
