<?php
/**
 * Milkyway Multimedia
 * SendThis.php
 *
 * This class throws exceptions, but can be set to not do so, in order to
 * work like the SS Mailer class which does not throw exceptions
 *
 * @package milkyway-multimedia/silverstripe-send-this
 * @author Mellisa Hankins <mellisa.hankins@me.com>
 */

class SendThis extends Mailer {
    /** @var array A map for the transports you can use with SendThis */
    private static $transports = [
        'smtp' => '\Milkyway\SS\SendThis\Transports\SMTP',
        'ses' => '\Milkyway\SS\SendThis\Transports\AmazonSES',
        'mandrill' => '\Milkyway\SS\SendThis\Transports\Mandrill',
    ];

    /** @var bool Whether to enabled logging for this application */
    private static $logging = true;

    /** @var bool Whether to enable api tracking for this application */
    private static $api_tracking = true;

    /** @var bool|string Only allow emails from a certain domain
     * (you can also enter an email here to override the From Address) */
    private static $from_same_domain_only = true;

    /** @var int After how many soft bounces do we blacklist */
    private static $blacklist_after_bounced = 2;

    /** @var array These are the registered listeners for SendThis */
    protected static $listeners = [];

    /** @var array These are the registered listeners for SendThis that will be only called once and then removed (callbacks if you will) */
    protected static $callbacks = [];

    /** @var bool Whether or not to throw exceptions */
	protected static $throw_exceptions = true;

	public static function get_throw_exceptions() {
		return self::$throw_exceptions;
	}

	public static function throw_exceptions($flag = true) {
		self::$throw_exceptions = $flag;
	}

    /**
     * Send an email immediately, with ability to provide a callback and alternate transport
     *
     * @param Email|array      $email
     * @param Callable  $callback
     * @param array $transport
     */
    public static function now($email, $callback = null, $transport = []) {
        //@todo implement a quick send function
        if($callback)
            static::listen('sent', $callback, true);
    }

    /**
     * Push an email to a queue, with ability to provide a time, callback, alternate transport
     *
     * @param Email|array      $email
     * @param string $time
     * @param Callable  $callback
     * @param array $transport
     */
    public static function later($email, $time = '', $callback = null, $transport = []) {
        //@todo implement a quick queue function
        if($callback)
            static::listen(['sent'], $callback, true);
    }

    public static function boot() {
        if(static::config()->disable_default_listeners)
            return;

        $listeners = (array) static::config()->listeners;

        if(count($listeners)) {
            foreach($listeners as $listener => $options) {
                $once = false;

                if(is_array($options)) {
                    $hooks = isset($options['events']) ? $options['events'] : user_error('The listener: ' . $listener . ' requires an events key to establish which events this listener will hook into');
                    $once = isset($options['first_time_only']);

                    if(isset($options['inject']))
                        $listener = $options['inject'];
                }
                else
                    $hooks = $options;

                if(is_array($listener)) {
                    $injectListener = array_shift($listener);
                    $listener = [Injector::inst()->create($injectListener)] + $listener;
                }
                else
                    $listener = Injector::inst()->create($listener);

                static::listen($hooks, $listener, $once);
            }
        }
    }

    /**
     * Add a listener to a event hook(s)
     *
     * @param array|string $hooks
     * @param Callable $item
     * @param bool $once Only call the event once (act like a callback)
     */
    public static function listen($hooks, $item, $once = false) {
        $hooks = (array) $hooks;

        foreach($hooks as $hook) {
            if($once) {
                if(!isset(static::$callbacks[$hook]))
                    static::$callbacks[$hook] = [];
            }
            elseif(!isset(static::$listeners[$hook]))
                static::$listeners[$hook] = [];

            if(!is_callable($item))
                $listener = [$item, $hook];
            else
                $listener = $item;

            if($once)
                static::$callbacks[$hook][] = $listener;
            else
                static::$listeners[$hook][] = $listener;
        }
    }

    /**
     * Fire an event(s)
     *
     * @param array|string $hooks
     */
    public static function fire($hooks) {
        $hooks = (array)$hooks;

        foreach($hooks as $hook) {
            if(isset(static::$listeners[$hook])) {
                $args = func_get_args();
                array_shift($args);

                foreach(static::$listeners[$hook] as $listener)
                    call_user_func_array($listener, $args);
            }

            if(isset(static::$callbacks[$hook])) {
                $args = func_get_args();
                array_shift($args);

                foreach(static::$callbacks[$hook] as $listener)
                    call_user_func_array($listener, $args);

                static::$callbacks[$hook] = [];
            }
        }
    }

    /** @var \Milkyway\SS\SendThis\Contracts\Transport The mail transport */
    protected $transport;

    /** @var PHPMailer  The PHP Mailer instance */
    protected $messenger;

    /** @var bool Whether there is no to email in current message */
    protected $noTo = false;

    /**
     * Split a name <email> string
     *
     * @param $string
     * @return array
     */
    public static function split_email($string) {
		if (preg_match('/^\s*(.+)\s+<(.+)>\s*$/', trim($string), $parts)){
			return array($parts[2], $parts[1]); // Has name and email
		} else {
			return array(trim($string), ''); // Has email
		}
	}

    /**
     * Get an administrator or default email
     *
     * @param string $prepend
     *
     * @return string
     */
    public static function admin_email($prepend = '') {
        if($email = Config::inst()->get('Email', 'admin_email'))
            return $prepend ? $prepend . '+' . $email : $email;

        $name = $prepend ? $prepend . '+no-reply' : 'no-reply';

        return $name . '@' . trim(str_replace(array('http://', 'https://', 'www.'), '', Director::protocolAndHost()), ' /');
    }

    /**
     * Get message id from headers
     *
     * @param $headers
     *
     * @return mixed
     */
    public static function message_id_from_headers($headers) {
        if(isset($headers['Message-ID']))
            return $headers['Message-ID'];

        if(isset($headers['X-SilverStripeMessageID']))
            return $headers['X-SilverStripeMessageID'];

        if(isset($headers['X-MilkywayMessageID']))
            return $headers['X-MilkywayMessageID'];

        return '';
    }

    public function __construct() {
        parent::__construct();
        $this->setMessenger();
    }

    protected function setMessenger() {
        $this->messenger = new PHPMailer(true);
        $this->setTransport();
    }

    protected function resetMessenger(){
        if($this->messenger) {
            $this->messenger->ClearAllRecipients();
            $this->messenger->ClearReplyTos();
            $this->messenger->ClearAttachments();
            $this->messenger->ClearCustomHeaders();
        }

        $this->noTo = false;
    }

    protected function setTransport() {
        $available = $this->config()->transports;
        $transport = $this->config()->transport;

        if($transport && isset($available[$transport]))
            $this->transport = Object::create($available[$transport], $this->messenger);
        else
            $this->transport = Object::create('\Milkyway\SendThis\Transports\SendThis_Default', $this->messenger);

        return $this->transport;
    }

	protected function addEmail($in, $func, $email = null, $break = false, $ignoreValid = false, $hidden = false) {
		if(!$email) $email = $this->messenger;

		$success = false;

		$list = explode(',', $in);
		foreach ($list as $item) {
			if(!trim($item)) continue;

			list($a,$b) = $this->split_email($item);

			if(SendThis_Blacklist::check($a, $ignoreValid))  {
				if($break)
					return false;
				else
					continue;
			}

			if(!$a || !Email::is_valid_address(($a)))
				continue;

			$success = true;

			if(!$hidden && $this->noTo) {
				$email->AddAddress($a, $b);
                $this->noTo = false;
			}
			else
				$email->$func($a, $b);
		}

		return $success;
	}

	protected function message($to, $from, $subject, $attachedFiles = null, $headers = null) {
		$email = $this->messenger;

		if(!$headers) $headers = array();

		$ignoreValid = false;

		if(isset($headers['X-Milkyway-Priority'])) {
            $ignoreValid = true;
			unset($headers['X-Milkyway-Priority']);
		}

		// set the to
		if(!$this->addEmail($to, 'addAddress', $email, $ignoreValid))
			$this->noTo = true;

		list($doFrom, $doFromName) = $this->split_email($from);

		if($sameDomain = static::config()->from_same_domain_only) {
			$base = '@' . \Milkyway\Director::baseWebsiteURL();

			if(!is_bool($sameDomain) || !$doFrom || !(substr($doFrom, -strlen($base)) === $base)) {
				$realFrom = $doFrom;
				$realFromName = $doFromName;

				if(!is_bool($sameDomain)) {
					$doFrom = $sameDomain;
					if(!$realFromName) $doFromName = ClassInfo::exists('SiteConfig') ? SiteConfig::current_site_config()->AdminName : singleton('LeftAndMain')->ApplicationName;
				}
				elseif(ClassInfo::exists('SiteConfig')) {
					$doFrom = SiteConfig::current_site_config()->AdminEmail;
					if(!$realFromName) $doFromName = SiteConfig::current_site_config()->AdminName;
				}
				else {
					$doFrom = $this->admin_email();
					if(!$realFromName) $doFromName = singleton('LeftAndMain')->ApplicationName;
				}

				if(is_bool($sameDomain) && !(substr($doFrom, -strlen($base)) === $base)) {
					$doFrom = $this->admin_email();
					if(!$realFromName) $doFromName = singleton('LeftAndMain')->ApplicationName;
				}

				$email->addReplyTo($realFrom, $realFromName);
			}
		}

		if(!$doFrom) {
			if(ClassInfo::exists('SiteConfig'))
				$doFrom = SiteConfig::current_site_config()->AdminEmail;
			else
				$doFrom = $this->admin_email();
		}

		$email->setFrom($doFrom, $doFromName);

		$email->Subject = $subject;

		if (is_array($attachedFiles)) {
			foreach($attachedFiles as $file) {
				if (isset($file['tmp_name']) && isset($file['name']))
					$email->addAttachment($file['tmp_name'], $file['name']);
				elseif (isset($file['contents']))
					$email->addStringAttachment($file['contents'], $file['filename']);
				else
					$email->addAttachment($file);
			}
		}

		if(is_array($headers) && count($headers)) {
			// the carbon copy header has to be 'Cc', not 'CC' or 'cc' -- ensure this.
			if (isset($headers['CC'])) { $headers['Cc'] = $headers['CC']; unset($headers['CC']); }
			if (isset($headers['cc'])) { $headers['Cc'] = $headers['cc']; unset($headers['cc']); }

			// the carbon copy header has to be 'Bcc', not 'BCC' or 'bcc' -- ensure this.
			if (isset($headers['BCC'])) {$headers['Bcc']=$headers['BCC']; unset($headers['BCC']); }
			if (isset($headers['bcc'])) {$headers['Bcc']=$headers['bcc']; unset($headers['bcc']); }

			if(isset($headers['Cc'])) {
				$this->addEmail($headers['Cc'], 'AddCC', $email, $ignoreValid);
				unset($headers['Cc']);
			}

			if(isset($headers['Bcc'])) {
				$this->addEmail($headers['Bcc'], 'AddBCC', $email, $ignoreValid);
				unset($headers['Bcc']);
			}

			if(isset($headers['X-SilverStripeMessageID'])) {
                if(!isset($headers['Message-ID']))
                    $headers['Message-ID'] = $headers['X-SilverStripeMessageID'];

                $headers['X-MilkywayMessageID'] = $headers['X-SilverStripeMessageID'];
                unset($headers['X-SilverStripeMessageID']);
			}

			if(isset($headers['X-SilverStripeSite'])) {
				$headers['X-MilkywaySite'] = ClassInfo::exists('SiteConfig') ? SiteConfig::current_site_config()->Title : singleton('LeftAndMain')->ApplicationName;
				unset($headers['X-SilverStripeSite']);
			}

			if(isset($headers['Reply-To'])) {
				$this->addEmail($headers['Reply-To'], 'AddReplyTo', $email, true);
				unset($headers['Reply-To']);
			}

			if(isset($headers['X-Priority'])) {
				$email->Priority = $headers['X-Priority'];
				unset($headers['X-Priority']);
			}
		}

		// Email has higher chance of being received if there is a too email sent...
		if($this->noTo && $to = Email::config()->default_to_email) {
			$this->addEmail($to, 'AddAddress', $email, $ignoreValid);
            $this->noTo = false;
        }

		$server = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : singleton('LeftAndMain')->ApplicationName;
		$email->XMailer = sprintf('SendThis Mailer 2.0 (Sent from %s)', $server);

		if($this->config()->confirm_reading_to)
			$email->ConfirmReadingTo = $this->config()->confirm_reading_to;

		if($this->config()->word_wrap)
			$email->WordWrap = $this->config()->word_wrap;

		foreach ($headers as $k => $v)
			$email->AddCustomHeader($k, $v);

		return $email;
	}

	public function send($type = 'html', $to, $from, $subject, $content, $attachedFiles = null, $headers = null, $plainContent = null) {
        $this->resetMessenger();

        if(static::config()->logging) {
		    $log = SendThis_Log::create()->init($type, $headers);
            $log->Transport = get_class($this->transport);
        }
        else
            $log = null;

        $messageId = $this->message_id_from_headers($headers);
        $params = compact('to', 'from', 'subject', 'content', 'attachedFiles', 'headers');

        $headers = (object) $headers;

        static::fire('up', $messageId, $to, $params, $params, $log, $headers);

        $headers = (array) $headers;

        $this->transport->applyHeaders($headers);
		$message = $this->message($to, $from, $subject, $attachedFiles, $headers);

		if($message) {
            $params['message'] = $message;

            $message->Body = $type != 'html' ? strip_tags($content) : $content;

            if($type == 'html' || $plainContent)
                $message->AltBody = $plainContent ? $plainContent : strip_tags($content);

            static::fire('sending', $messageId, $to, $params, $params, $log);

            $message->isHTML($type == 'html');

			try {
				$result = $this->transport->start($message, $log);
			} catch(Exception $e) {
				$result = $e->getMessage();

                $params['message'] = $result;

                if(self::$throw_exceptions) {
                    static::fire('failed', $message->getLastMessageID() ?: $messageId, $to, $params, $params, $log);
                    throw $e;
                }
			}

			if(!$result || $message->IsError())
				$result = $message->ErrorInfo;

            $messageId = $message->getLastMessageID() ?: $messageId;
		}
        else
            $result = 'Email has been unsubscribed/blacklisted';

        if($result !== true) {
            $params['message'] = $result;
            static::fire('failed', $messageId, $to, $params, $params, $log);
        }

        static::fire('down', $messageId, $to, $params, $params, $log);
        static::$callbacks = [];

        $this->resetMessenger();

		return $result;
	}

    public function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = null, $headers = null, $plainContent = null) {
        return $this->send('html', $to, $from, $subject, $htmlContent, $attachedFiles, $headers, $plainContent);
    }

    public function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = null, $headers = null) {
        return $this->send('plain', $to, $from, $subject, $plainContent, $attachedFiles, $headers);
    }
}

class SendThis_Exception extends Exception { }