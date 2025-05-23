<?php
namespace wheelform;

use Craft;
use craft\helpers\FileHelper;
use craft\helpers\StringHelper;
use craft\mail\Message as MailMessage;
use wheelform\events\SendEvent;
use wheelform\db\Form;
use wheelform\helpers\TagHelper;
use yii\base\InvalidConfigException;
use yii\base\Component;


class Mailer extends Component
{
    const EVENT_BEFORE_SEND = 'beforeSend';
    const EVENT_AFTER_SEND = 'afterSend';

    const EVENT_BEFORE_NOTIFICATION_SEND = 'beforeNotificationSend';

    protected $defaultTemplate = 'wheelform/_emails/general.twig';
    protected $notificationTemplate = 'wheelform/_emails/notification.twig';

    protected $config = [
        'template' => '',
        'notification' =>  [
            'template' => '',
            'subject' => '',
        ],
    ];

    protected $form = null;

    protected $message = null;

    public function __construct()
    {
        $config = Craft::$app->getConfig();
        $customConfig = $config->getConfigFromFile('wheelform');
        if(! empty($customConfig) && is_array($customConfig)) {
            $this->config = array_replace_recursive($this->config, $customConfig);
        }
    }

    public function send(Form $form, array $message): bool
    {
        // Get the plugin settings and make sure they validate before doing anything
        $settings = Plugin::getInstance()->getSettings();
        if (!$settings->validate()) {
            throw new InvalidConfigException(Craft::t('wheelform', "Plugin settings need to be configured."));
        }

        $this->form = $form;
        $this->message = $message;

        // Prep the message Variables
        $defaultSubject = $this->form->name . " - " . Craft::t("wheelform", 'Submission');
        $subject = (!empty($this->form->options['email_subject']) ? $this->form->options['email_subject'] : $defaultSubject);
        // Grab any "to" emails set in the form settings.
        $to_emails = StringHelper::split($this->form->to_email);
        $mailMessage = new MailMessage();
        $mailer = Craft::$app->getMailer();
        $text = '';
        $from = null;

        if(! empty($this->config['forms'][$this->form->id]['from'])) {
            $from = $this->config['forms'][$this->form->id]['from'];
        } else {
            $from_email = $settings->from_email;
            $from_name = empty($settings->from_name) ? ""  : $settings->from_name;

            $from = $from_email;

            if(!empty($from_name)) {
                $from = [
                    $from_email => $from_name
                ];
            }
        }

        $beforeEvent = new SendEvent([
            'form_id' => $this->form->id,
            'subject' => $subject,
            'message' => $message,
            'from' => $from,
            'to' => $to_emails,
            'reply_to' => $this->getReplyToEmail(),
            'email_html' => '',
        ]);

        $this->trigger(self::EVENT_BEFORE_SEND, $beforeEvent);

        // Gather Tags
        $tags = TagHelper::getTags([
            $beforeEvent->subject,
            ($this->form->options['user_notification_subject'] ?? ''),
            ($this->form->options['user_notification_message'] ?? ''),
        ]);

        //gather message
        if(! empty($beforeEvent->message))
        {
            foreach($beforeEvent->message as $k => $m)
            {
                $text .= ($text ? "\n" : '')."- **{$m['label']}:** ";

                switch ($m['type'])
                {
                    case 'file':
                        $beforeEvent->message[$k]['value'] = NULL;
                        if(! empty($m['value'])){
                            $attachment = json_decode($m['value']);
                            if (!empty($attachment)) {
                                // Only Attach files that are stored locally
                                if (!empty($attachment->filePath) && empty($this->config['forms'][$this->form->id]['skip_attachments'])) {
                                    $mailMessage->attach($attachment->filePath, [
                                        'fileName' => $attachment->name,
                                        'contentType' => FileHelper::getMimeType($attachment->filePath),
                                    ]);
                                }
                                $text .= (!empty($attachment->name) ? $attachment->name : Craft::t("wheelform", 'Uploaded file'));
                                // Prepare for Twig
                                $beforeEvent->message[$k]['value'] = $attachment;
                            } else {
                                $text .= Craft::t("wheelform", 'Uploaded file not found');
                            }
                        }
                        break;
                    case 'checkbox':
                    case 'select':
                    case 'consent':
                        $text .= (is_array($m['value']) ? implode(', ', $m['value']) : $m['value']);
                        break;
                    case 'list':
                        if(! is_array($m['value']) || empty($m['value'])) {
                            $text .= "";
                        } else {
                            foreach($m['value'] as $value) {
                                $text .= "\n*" . $value;
                            }
                        }
                        break;
                    default:
                        //Text, Email, Number
                        if (array_key_exists($k, $tags) && !empty($m['value'])) {
                            $tags[$k] = $m['value'];
                        }
                        $text .= $m['value'];
                        break;
                }
            }
        }

        if(! empty($beforeEvent->email_html)) {
            $html_body = $beforeEvent->email_html;
        } else {
            $html_body = $this->compileHtmlBody($beforeEvent->message);
        }

        $beforeEvent->subject = TagHelper::replacePlaseholders($beforeEvent->subject, $tags);

        $mailMessage->setFrom($beforeEvent->from);
        $mailMessage->setSubject($beforeEvent->subject);
        $mailMessage->setTextBody($text);
        $mailMessage->setHtmlBody($html_body);

        if(! empty($beforeEvent->reply_to)) {
            $mailMessage->setReplyTo($beforeEvent->reply_to);
        }

        if(is_array($beforeEvent->to)) {
            foreach ($beforeEvent->to as $to_email) {
                $to_email = trim($to_email);
                $mailMessage->setTo($to_email);
                $mailer->send($mailMessage);
            }
        } else {
            $to_email = trim($beforeEvent->to);
            $mailMessage->setTo($to_email);
            $mailer->send($mailMessage);
        }

        $afterEvent = new SendEvent([
            'form_id' => $beforeEvent->form_id,
            'subject' => $beforeEvent->subject,
            'message' => $beforeEvent->message,
            'from' => $beforeEvent->from,
            'to' => $beforeEvent->to,
            'reply_to' => $beforeEvent->reply_to,
            'email_html' => $beforeEvent->email_html,
        ]);
        $this->trigger(self::EVENT_AFTER_SEND, $afterEvent);

        $sendNotification = (empty($this->form->options['user_notification']) ? false : boolval($this->form->options['user_notification']));
        if($sendNotification) {
            $notificationTo = "";
            foreach($this->form->fields as $field) {
                if($field->type !== 'email') {
                    continue;
                }

                if(! empty($field->options['is_user_notification_field'])) {
                    $notificationTo = $beforeEvent->message[$field->name]['value'];
                }
            }

            if(! empty($notificationTo)) {
                $notificationSubject = $this->form->name . " - " . Craft::t("wheelform", 'Notification');

                // User CP Subject
                if (!empty($this->form->options['user_notification_subject'])) {
                    $notificationSubject = TagHelper::replacePlaseholders($this->form->options['user_notification_subject'], $tags);
                }

                // Generic Notification Subject
                if(! empty($this->config['notification']['subject'])) {
                    $notificationSubject = $this->config['notification']['subject'];
                }

                //Form specific Notification Subject
                if(! empty($this->config['forms'][$this->form->id]['notification']['subject'])) {
                    $notificationSubject = TagHelper::replacePlaseholders($this->config['forms'][$this->form->id]['notification']['subject'], $tags);
                }

                $notificationText = "";
                if (! empty($this->form->options['user_notification_message'])) {
                    $notificationText = TagHelper::replacePlaseholders($this->form->options['user_notification_message'], $tags);
                }

                $notificationHtml = $this->getNotificationHtml($beforeEvent->message, $notificationText);

                $beforeNotificationEvent = new SendEvent([
                    'form_id' => $this->form->id,
                    'subject' => $notificationSubject,
                    'from' => $beforeEvent->from,
                    'reply_to' => $beforeEvent->to,
                ]);
                $this->trigger(self::EVENT_BEFORE_NOTIFICATION_SEND, $beforeNotificationEvent);

                $userNotification = new MailMessage();
                $userNotification->setFrom($beforeNotificationEvent->from);
                $userNotification->setSubject($beforeNotificationEvent->subject);
                $userNotification->setTextBody($notificationText);
                $userNotification->setHtmlBody($notificationHtml);
                $userNotification->setTo($notificationTo);

                if(! empty($beforeNotificationEvent->reply_to)) {
                    $userNotification->setReplyTo($beforeNotificationEvent->reply_to);
                }

                $mailer->send($userNotification);
            }
        }

        return true;
    }

    protected function getReplyToEmail()
    {
        foreach($this->form->fields as $field) {
            if($field->type !== "email") {
                continue;
            }

            if(! empty($field->options['is_reply_to'])) {
                if(! empty($this->message[$field->name]['value'])) {
                    return $this->message[$field->name]['value'];
                }
            }
        }

        return "";
    }

    public function compileHtmlBody(array $variables)
    {
        /**
         * @var craft/web/View
         */
        $view = Craft::$app->getView();
        $currentMode = $view->getTemplateMode();
        $isFrontTemplate = true;
        $template = '';

        if(! empty($this->config['template'])) {
            $template = $this->config['template'];
        }

        if(! empty($this->config['forms'][$this->form->id]['template'])) {
            $template = $this->config['forms'][$this->form->id]['template'];
        }

        if(empty($template) || ! is_string($template)) {
            $isFrontTemplate = false;
            $template = $this->defaultTemplate;
        }

        $currentViewMode = $isFrontTemplate ? $view::TEMPLATE_MODE_SITE : $view::TEMPLATE_MODE_CP;
        $view->setTemplateMode($currentViewMode);
        $html = $view->renderTemplate($template, [
            'fields' => $variables
        ]);

        // Reset
        $view->setTemplateMode($currentMode);

        return $html;
    }

    public function getNotificationHtml(array $variables, string $notificationMessage)
    {
        /**
         * @var craft/web/View
         */
        $view = Craft::$app->getView();
        $currentMode = $view->getTemplateMode();
        $isFrontTemplate = true;
        $template = '';

        if (! empty($this->config['notification']['template'])) {
            $template = $this->config['notification']['template'];
        }

        if (! empty($this->config['forms'][$this->form->id]['notification']['template'])) {
            $template = $this->config['forms'][$this->form->id]['notification']['template'];
        }

        if(empty($template) || ! is_string($template)) {
            $isFrontTemplate = false;
            $template = $this->notificationTemplate;
        }

        $currentViewMode = $isFrontTemplate ? $view::TEMPLATE_MODE_SITE : $view::TEMPLATE_MODE_CP;
        $view->setTemplateMode($currentViewMode);
        $html = $view->renderTemplate($template, [
            'notification_message' => $notificationMessage,
            'fields' => $variables,
        ]);

        // Reset
        $view->setTemplateMode($currentMode);

        return $html;
    }
}
