<?php
namespace PharmaSure\Integrations\Adapters;
use PharmaSure\Integrations\Contracts\Adapter;
final class WordPressEmailAdapter implements Adapter{public function key():string{return 'wordpress_email';}public function type():string{return 'email';}public function deliver(array $config,array $payload):array{$to=array_values(array_filter(array_map('sanitize_email',(array)($payload['to']??array()))));$subject=sanitize_text_field($payload['subject']??'');$body=(string)($payload['body']??'');if(!$to||!$subject||!$body){throw new \DomainException('Email recipients, subject and body are required.');}$sent=wp_mail($to,$subject,$body);return array('success'=>$sent,'code'=>$sent?202:503,'body'=>$sent?'accepted':'wp_mail failed');}}
