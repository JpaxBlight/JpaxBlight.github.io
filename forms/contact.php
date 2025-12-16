<?php
  /**
  * Requires the "PHP Email Form" library
  * The "PHP Email Form" library is available only in the pro version of the template
  * The library should be uploaded to: vendor/php-email-form/php-email-form.php
  * For more info and help: https://bootstrapmade.com/php-email-form/
  */

  // Replace contact@example.com with your real receiving email address
  $receiving_email_address = 'alcomendasjpax@gmail.com';

  if( file_exists($php_email_form = '../assets/vendor/php-email-form/php-email-form.php' )) {
    include( $php_email_form );
  } else {
    // Fallback minimal implementation when the library is not available.
    // This implements the small subset used by this contact form: add_message(), send(), and optional SMTP.
    class PHP_Email_Form {
      public $ajax = true;
      public $to = '';
      public $from_name = '';
      public $from_email = '';
      public $subject = '';
      public $smtp = array(); // optional: ['host'=>'','username'=>'','password'=>'','port'=>587,'secure'=>'tls']
      private $messages = array();

      public function add_message($value, $key = '', $priority = 0) {
        $this->messages[] = array('key'=>$key, 'value'=>$value);
      }

      private function sanitize($str) {
        // Prevent header injection
        return trim(str_replace(array("\r", "\n"), array('',''), $str));
      }

      private function validate_email($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
      }

      public function send() {
        if (empty($this->to) || ! $this->validate_email($this->to)) return 'No valid recipient provided.';
        if (empty($this->from_email) || ! $this->validate_email($this->from_email)) return 'No valid sender email provided.';

        $subject = $this->sanitize($this->subject ?: 'Contact Form Submission');

        // Build message body
        $body = "";
        foreach ($this->messages as $m) {
          $k = $m['key'] ? $m['key'] : 'Message';
          $body .= $this->sanitize($k) . ": " . trim($m['value']) . "\n";
        }

        $from = $this->sanitize($this->from_email);
        $from_name = $this->sanitize($this->from_name);

        // Use SMTP if configured
        if (!empty($this->smtp) && !empty($this->smtp['host'])) {
          $smtpResult = $this->smtp_send($this->smtp, $this->to, $subject, $body, $from, $from_name);
          if ($smtpResult === true) return 'OK';
          return $smtpResult; // error string
        }

        // Fallback to PHP mail()
        $headers = "From: {$from_name} <{$from}>\r\n";
        $headers .= "Reply-To: {$from}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-type: text/plain; charset=UTF-8\r\n";

        $ok = @mail($this->to, $subject, $body, $headers);
        if ($ok) return 'OK';
        return 'Could not send email using mail() function.';
      }

      private function smtp_send($smtp, $to, $subject, $body, $from, $from_name) {
        $host = $smtp['host'];
        $username = isset($smtp['username']) ? $smtp['username'] : '';
        $password = isset($smtp['password']) ? $smtp['password'] : '';
        $port = isset($smtp['port']) ? (int)$smtp['port'] : 587;
        $secure = isset($smtp['secure']) ? strtolower($smtp['secure']) : 'tls'; // tls, ssl, or none

        $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $timeout = 30;
        $errno = 0; $errstr = '';
        $fp = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
        if (!$fp) return "SMTP connection failed: {$errstr} ({$errno})";

        stream_set_timeout($fp, $timeout);
        $res = $this->smtp_read($fp);
        if (strpos($res, '220') !== 0) return 'SMTP error: ' . $res;

        $hostname = gethostname() ?: 'localhost';
        $this->smtp_write($fp, "EHLO {$hostname}\r\n");
        $res = $this->smtp_read($fp);

        if ($secure === 'tls') {
          $this->smtp_write($fp, "STARTTLS\r\n");
          $res = $this->smtp_read($fp);
          if (strpos($res, '220') !== 0) return 'SMTP STARTTLS failed: ' . $res;
          if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) return 'Failed to enable TLS for SMTP connection.';
          // EHLO again after TLS
          $this->smtp_write($fp, "EHLO {$hostname}\r\n");
          $res = $this->smtp_read($fp);
        }

        if ($username !== '') {
          $this->smtp_write($fp, "AUTH LOGIN\r\n");
          $res = $this->smtp_read($fp);
          if (strpos($res, '334') !== 0) return 'SMTP AUTH not accepted: ' . $res;
          $this->smtp_write($fp, base64_encode($username) . "\r\n");
          $res = $this->smtp_read($fp);
          $this->smtp_write($fp, base64_encode($password) . "\r\n");
          $res = $this->smtp_read($fp);
          if (strpos($res, '235') !== 0) return 'SMTP authentication failed: ' . $res;
        }

        $this->smtp_write($fp, "MAIL FROM: <{$from}>\r\n");
        $res = $this->smtp_read($fp);
        if (strpos($res, '250') !== 0) return 'SMTP MAIL FROM failed: ' . $res;

        $this->smtp_write($fp, "RCPT TO: <{$to}>\r\n");
        $res = $this->smtp_read($fp);
        if (strpos($res, '250') !== 0 && strpos($res, '251') !== 0) return 'SMTP RCPT TO failed: ' . $res;

        $this->smtp_write($fp, "DATA\r\n");
        $res = $this->smtp_read($fp);
        if (strpos($res, '354') !== 0) return 'SMTP DATA not accepted: ' . $res;

        $headers = "From: {$from_name} <{$from}>\r\n";
        $headers .= "To: {$to}\r\n";
        $headers .= "Subject: {$subject}\r\n";
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "\r\n";

        $data = $headers . $body . "\r\n.\r\n";
        $this->smtp_write($fp, $data);
        $res = $this->smtp_read($fp);
        if (strpos($res, '250') !== 0) return 'SMTP message not accepted: ' . $res;

        $this->smtp_write($fp, "QUIT\r\n");
        fclose($fp);
        return true;
      }

      private function smtp_write($fp, $string) {
        fwrite($fp, $string);
      }

      private function smtp_read($fp) {
        $data = '';
        while ($str = fgets($fp, 515)) {
          $data .= $str;
          // more lines in reply if 4th char is '-' (e.g., 250-)
          if (isset($str[3]) && $str[3] == ' ') break;
        }
        return trim($data);
      }
    }
  }

  $contact = new PHP_Email_Form;
  $contact->ajax = true;
  
  $contact->to = $receiving_email_address;
  $contact->from_name = isset($_POST['name']) ? trim($_POST['name']) : '';
  $contact->from_email = isset($_POST['email']) ? trim($_POST['email']) : '';
  $contact->subject = isset($_POST['subject']) ? trim($_POST['subject']) : 'New message from contact form';

  // Uncomment and fill in SMTP credentials to use SMTP (optional). For Gmail with 2FA, create an App Password.
  /*
  $contact->smtp = array(
    'host' => 'smtp.gmail.com',
    'username' => 'your-email@gmail.com',
    'password' => 'your-app-password',
    'port' => 587,
    'secure' => 'tls' // 'tls', 'ssl', or 'none'
  );
  */

  $contact->add_message( $_POST['name'], 'From');
  $contact->add_message( $_POST['email'], 'Email');
  isset($_POST['phone']) && $contact->add_message($_POST['phone'], 'Phone');
  $contact->add_message( $_POST['message'], 'Message', 10);

  echo $contact->send();
?>
