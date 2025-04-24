<?php
/* this contains the class for the Messengers
    * @package Event Tickets with Ticket Scanner
    * @author  Vollstart
    *
    * For Plugin Name: Event Tickets with Ticket Scanner
*/
include_once(plugin_dir_path(__FILE__)."init_file.php");
class sasoEventtickets_Messenger {
    private $MAIN;

    public static function Instance() {
		static $inst = null;
        if ($inst === null) {
            $inst = new sasoEventtickets_Messenger();
        }
        return $inst;
	}

    public function __construct() {
        global $sasoEventtickets;
		$this->MAIN = $sasoEventtickets;
    }

    /*
    public function initHandlers() {
        // ??? should all teh options be set and handled here?
        // should we use the  action hook to execute the sending?
        // add_action('sasoeventtickets_sendFileViaMessenger', array($this, 'handleRequest')); ???
    }
        */

    public function sendMessage($message, $phoneNumber, $type) {
        $this->checkType($type);
        switch ($type) {
            case 'whatsapp':
                return $this->sendWhatsAppMessage($message, $phoneNumber);
            case 'telegram':
                return $this->sendTelegramMessage($message, $phoneNumber);
            default:
                throw new Exception('#2001 Unsupported messenger type');
        }
    }
    public function sendFile($filepath, $message, $phoneNumber, $type) {
        $this->checkType($type);
        switch ($type) {
            case 'whatsapp':
                return $this->sendWhatsAppMessageFile($filepath, $message, $phoneNumber);
            case 'telegram':
                return $this->sendTelegramMessageFile($filepath, $message, $phoneNumber);
            default:
                throw new Exception('#2002 Unsupported messenger type');
        }
    }

    private function sendWhatsAppMessage($message, $phoneNumber) {

        // maybe this https://medium.com/@wassenger/send-automated-messages-on-whatsapp-using-php-26cad1781c2c
        // for now the code below is created by code generator


        // WhatsApp API URL
        $url = "https://api.whatsapp.com/send?phone=$phoneNumber&text=" . urlencode($message);

        // Use file_get_contents to send the message
        $response = file_get_contents($url);

        // Check for errors
        if ($response === FALSE) {
            throw new Exception('#2003 Error sending WhatsApp message');
        }

        return $response;
    }
    private function sendTelegramMessage($message, $chatId) {
        // code genereted by code generator
        // Telegram API URL
        $url = "https://api.telegram.org/bot" . $this->get_TELEGRAM_BOT_TOKEN() . "/sendMessage?chat_id=$chatId&text=" . urlencode($message);
        // Use file_get_contents to send the message
        $response = file_get_contents($url);
        // Check for errors
        if ($response === FALSE) {
            throw new Exception('#2004 Error sending Telegram message');
        }
        return $response;
    }

    private function sendWhatsAppMessageFile($filepath, $message, $phoneNumber) {
        //TODO: Implement WhatsApp file sending
        //??
    }

    private function sendTelegramMessageFile($filepath, $message, $chatId) {
        // Telegram API URL
        $url = "https://api.telegram.org/bot" . $this->get_TELEGRAM_BOT_TOKEN() . "/sendDocument";

        // Prepare the POST fields
        $postFields = [
            'chat_id' => $chatId,
            'caption' => $message,
            'document' => new CURLFile(realpath($filepath))
        ];

        // Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Execute the request
        $response = curl_exec($ch);

        // Check for errors
        if ($response === FALSE) {
            throw new Exception('#2005 Error sending Telegram message with file');
        }

        // Close cURL
        curl_close($ch);

        return $response;
    }

    private function checkType($type) {
        if ($type === null) {
            throw new InvalidArgumentException('#2006 Messenger type cannot be null');
        }
        if (!in_array($type, ['whatsapp', 'telegram'])) {
            throw new InvalidArgumentException('#2007 Invalid messenger type');
        }
    }

    private function get_TELEGRAM_BOT_TOKEN() {
        //TODO: Implement a secure way to get the Telegram bot token - the options are readable from other plugins, so encrypt it
    }
    private function get_WHATSAPP_APIKEY() {
        //TODO: Implement a secure way to get the whatsapp apikey - the options are readable from other plugins, so encrypt it
    }

}

?>