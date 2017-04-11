<?php namespace App;

use Illuminate\Database\Eloquent\Model;

use Config;
use Mailgun;
use PhpImap\Mailbox as ImapMailbox;
use PhpImap\IncomingMail;
use PhpImap\IncomingMailAttachment;
use Log;
use Exception;

class CommunicationIntegration extends Model {

	const TWILIO_SUPPORT_NUMBER_US = '+15555555555';
	const TWILIO_SUPPORT_NUMBER_CA = '+16666666666';
	const PLIVO_SUPPORT_NUMBER = '1234567890';

	const EMAIL_COMPLAINED = -3;
	const EMAIL_FAILED = -2;
	const EMAIL_REJECTED = -1;
	const EMAIL_DELIVERED = 1;
	const EMAIL_OPENED = 2;
	const EMAIL_CLICKED = 3;

	/**
	 * Return a human readable description that matches the status code
	 * @param     int    $status    The value stored in vpn_lead_communications.event_status
	 * @return    string            The human readable text
	 */
	public static function getEmailStatus($status){
		$options = array (
					self::EMAIL_COMPLAINED => 'Customer complained (spam)',
					self::EMAIL_FAILED => 'Mailgun could not deliver the email to the recipient email server',
					self::EMAIL_REJECTED => 'Mailgun rejected the request to send/forward the email',
					self::EMAIL_DELIVERED => 'Delivered',
					self::EMAIL_OPENED => 'Read',
					self::EMAIL_CLICKED => 'Link clicked',
				);

		return !empty($options[$status]) ? $options[$status] : false;
	}

	/**
	 * This wrapper function is used to send SMS messages via Twillio
	 * @param  string 	$phone 	The phone number we are sending the SMS too
	 * @param  string 	$body  	The contents of the phone number
	 */
	public static function sendTwilioSMS($from, $to, $message){
		$sid = Config::get('api.twilio.sid'); // Your Account SID from www.twilio.com/user/account
		$token = Config::get('api.twilio.token'); // Your Auth Token from www.twilio.com/user/account

		$client = new \Services_Twilio($sid, $token);
		$response = $client->account->messages->sendMessage(
			$from, 	// From a valid Twilio number
			$to, 	// Text this number
			html_entity_decode($message, ENT_QUOTES) 	// The SMS body
		);

		if ($response->error_message){
			Log::error(print_r ($response, true));
			throw new Exception($response->error_message);
		}

		return true;
	}

	/**
	 * Determine who the phone carrier is and what type of phone this is using the Twilio API
	 * @param     string    $phoneNumber    The phone number to look up
	 * @param     string    $type           Wether we want the phone type or the carrier name
	 * @return    string                    The result of the request type or carrier name
	 */
	public static function phoneCarrierLookup($phoneNumber, $type){
		$sid = Config::get('api.twilio.sid'); // Your Account SID from www.twilio.com/user/account
		$token = Config::get('api.twilio.token'); // Your Auth Token from www.twilio.com/user/account
		$result = '';

		try {
			$lookup = new \Lookups_Services_Twilio($sid, $token);

			$response = $lookup->phone_numbers->get($phoneNumber, array("Type" => "carrier"));

			switch($type){
				case 'type':
					$result = $response->carrier->type;
					break;
				case 'carrier':
					$result = $response->carrier->name;
			}
		} catch (Exception $e) {
			// If a 404 exception was encountered return false.
			if($e->getStatus() == 404) {
				return false;
			} else {
				throw $e;
			}
		}
		
		return !empty($result) ? $result : false;
	}

	/**
	 * This wrapper function is used to retrive phone numbers available via Twilio
	 */
	public static function getTwilioPhoneNumbers() {
		$sid = Config::get('api.twilio.sid'); // Your Account SID from www.twilio.com/user/account
		$token = Config::get('api.twilio.token'); // Your Auth Token from www.twilio.com/user/account

		$client = new \Services_Twilio($sid, $token);

		$response = $client->account->incoming_phone_numbers;

		return !empty($response) ? $response : false;
	}

	/**
	 * Removes any spaces, dashes or brackets from a phone number
	 * This will leave the number with only valid numerical digits.
	 * @param     string    $phoneNumber    The phone number to modify
	 * @return    string                    The modified phone number
	 */
	public static function formatPhoneNumber($phoneNumber){
		return preg_replace('/\D/', '', $phoneNumber);
	}


	/**
	 * Send email messages via the Mailgun API
	 * @param     string    $emailTo    The email address the message should be sent to
	 * @param     string    $subject    The subject of the email message
	 * @param     string    $body       The main message that will be sent
	 * @param     string    $brand      The brand that the customer registered under. This is needed to get our get our settings
	 * @return    object                The result object returned by Mailgun
	 */
	public static function sendMailgunEmail($vpnLeadCommunicationId, $emailTo, $subject, $body, $brand, $attachments = null){
		$settings = self::getEmailSettings($brand);
		if (empty($settings)) return false;

		Config::set('mailgun.domain', $settings['domain']);

		$data['msg'] = nl2br($body);

		$status = Mailgun::send('emails.custom-bulk', $data, function($message) use ($vpnLeadCommunicationId, $emailTo, $settings, $subject, $attachments){
			$message->to($emailTo); 
			$message->from($settings['from']['address'], $settings['from']['name']);
			$message->replyTo($settings['replyTo']);
			$message->subject($subject);
			$message->tracking(true);
			$message->trackClicks(true);
			$message->trackOpens(true);
			$message->tag('Symbiosis');
			$message->data('vpn_lead_communication_id', $vpnLeadCommunicationId);

			if (!empty($attachments)){
				$message->attach('https://s3-us-west-2.amazonaws.com/'. Config::get('api.s3-bucket.attachments') .'/'.urlencode($attachments[0]['filename']));
			}
		});

		if (!$status || $status->http_response_code != 200){
			Log::error(print_r ($status, true));
			throw new Exception ('Support email was not sent via mailgun for Unknown reason.');
		}

		return !empty($status) ? $status : false;
	}

	/**
	 * Mailgun email settings
	 * @param     string    $brand    The brand that the customer registered under
	 * @return    array              An array of required settings in order for Mailgun to send email
	 */
	public static function getEmailSettings($brand){
		$settings = array(
			'NetSpeed Research' => array(
				'from' => array(
					'address' => 'panel@mailer.netspeedresearch.com',
					'name' => 'NetSpeed Research Panel'
				),
				'replyTo' => 'panel@mailer.netspeedresearch.com',
				'domain' => 'mailer.netspeedresearch.com',
			),
			'UniteResearch' => array(
				'from' => array(
					'address' => 'panel@mailer.uniteresearch.com',
					'name' => 'UniteResearch Team'
				),
				'replyTo' => 'panel@mailer.uniteresearch.com',
				'domain' => 'mailer.uniteresearch.com',
			),
		);

		return !empty($settings[$brand]) ? $settings[$brand] : false;
	}

	/**
	 * This wrapper function is used to get emails from IMAP (in this case ZohoMail)
	 * @param  int 	$limit 	to limit the required emails returned
	 */
	public static function getZohoMail($limit = null){
		$email = Config::get('api.zoho.email');
		$pass = Config::get('api.zoho.pass');

		$server = new \Fetch\Server('imap.zoho.com', 993);
		$server->setAuthentication($email, $pass);

		if(isset($limit)){
			$messages = $server->getOrderedMessages(1, true, $limit);
		} else {
			$messages = $server->getMessages(2);
		}

		return !empty($messages) ? $messages : false;
	}

	/**
	 * This wrapper function is used to get emails from IMAP (in this case ZohoMail) by searching for emails that contain search email
	 * @param  string 	$search 	the string used to search via IMAP search function
	 * @param  int 	$limit 	to limit the required emails returned
	 */
	public static function getUserEmails($search, $limit = null) {
		$email = Config::get('api.zoho.email');
		$pass = Config::get('api.zoho.pass');

		$server = new \Fetch\Server('imap.zoho.com', 993);
		$server->setAuthentication($email, $pass);

		if(isset($limit)){
			$messages = $server->search($search, $limit);
		} else {
			$messages = $server->search($search, 2);
		}

		return !empty($messages) ? $messages : false;
		
	}
}
