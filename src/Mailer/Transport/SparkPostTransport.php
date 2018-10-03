<?php

/*
 * Copyright (c) 2015 Syntax Era Development Studio
 *
 * Licensed under the MIT License (the "License"); you may not use this
 * file except in compliance with the License. You may obtain a copy of
 * the License at
 *
 *      https://opensource.org/licenses/MIT
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace ScidSparkPost\Mailer\Transport;

use Cake\Core\Configure;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Email;
use Cake\Network\Exception\BadRequestException;
use Cake\Http\Client as CakeClient;
use Cake\Utility\Text;
use Http\Adapter\Cake\Client as CakeAdaptor;
use Migrations\CakeAdapter;
use ScidSparkPost\Utility\ScidSparkPostRecipients;
use SparkPost\SparkPost;
use SparkPost\SparkPostResponse;

/**
 * Spark Post Transport Class
 *
 * Provides an interface between the CakePHP Email functionality and the SparkPost API.
 *
 * @package SparkPost\Mailer\Transport
 */
class SparkPostTransport extends AbstractTransport
{
    protected $_receipients = []; // array of recipients per sparkpost formats
    protected $_substitution_data = [];
    protected $_description = NULL; //Description of the transmission. Maximum length - 1024 bytes
    protected $_campaign_id = NULL; //Name of the campaign. Maximum length - 64 bytes

    /**
     * Send mail via SparkPost REST API
     *
     * @param \Cake\Mailer\Email $email Email message
     * @return array
     */
    public function send(Email $email) {
        // Load SparkPost configuration settings
        if (empty($this->_config['apiKey'])) {
            $this->_config['apiKey'] = $this->getConfig('ScidSparkPost.apiKey');
        }

        $isDebug = Configure::read('debug');
        // Set up HTTP request adapter
        $adapter = new CakeAdaptor(new CakeClient());

        // Create SparkPost API accessor
        $sparkpost = new SparkPost($adapter, ['key' => $this->_config['apiKey']]);
        $sparkpost->setOptions(['async' => FALSE]);
        // Pre-process CakePHP email object fields
        $fromArray = (array)$email->getFrom();
        $from = ['email' => 'sdevore@scidsolutions.com'];
        if (!empty($fromArray[$from['email']]) && $fromArray[$from['email']] != $from['email']) {
            $from['name'] = $fromArray[$from['email']];
        }
        if (empty($this->_receipients)) {
            $toArray = $email->getTo();
            $recipients = [];
            foreach ($toArray as $email => $name) {
                $to = [
                    'address' => ['email' => $email],
                ];
                if (!$email != $name) {
                    $to['address']['name'] = $name;
                }
                $recipients[] = $to;
            }
        } else {
            $recipients = $this->_receipients;
        }
        $message = [
            'recipients' => $recipients,
            'content'    => [
                'from'    => $from,
                'subject' => $email->getSubject(),
                'html'    => empty($email->message('html')) ? $email->message('text') : $email->message('html'),
                'text'    => $email->message('text'),
            ],
        ];
        if (!empty($email->getCc())) {
            $CCs = $email->getCc();
            foreach ($CCs as $email => $name) {
                $cc = [
                    'address' => ['email' => $email],
                ];
                if (!$email != $name) {
                    $cc['address']['name'] = $name;
                }
                $message['cc'][] = $cc;
            }
        }
        if (!empty($email->getBcc())) {
            $BCCs = $email->getBcc();
            foreach ($BCCs as $email => $name) {
                $bcc = [
                    'address' => ['email' => $email],
                ];
                if (!$email != $name) {
                    $bcc['address']['name'] = $name;
                }
                $message['bcc'][] = $bcc;
            }
        }


        // Build message to send
        if (!empty($this->_description)) {
            $message['description'] = $this->_description;
        }
        if (!empty($this->_campaign_id)) {
            $message['campaign_id'] = $this->_campaign_id;
        }
        if (!empty($this->_substitution_data)) {
            $message['substitution_data'] = $this->_substitution_data;
        }

        // Send message
        /** @var SparkPostResponse $response */
        $response = $sparkpost->transmissions->post($message);
        try {
            $status = $response->getStatusCode() == 200 ? 'success' : 'failure';
            $return = [
                'result' => $status,
                'code'   => $response->getStatusCode(),
                'body'   => $response->getBody(),
            ];
            return $return;
        } catch (\Exception $e) {
            // TODO: Determine if BRE is the best exception type
            throw new BadRequestException(__('SparkPost API error {0}: {1}',
                                             [$e->getCode(), ucfirst($e->getMessage())]));
        }
    }

    /**
     * @param $description Description of the transmission. Maximum length - 1024 bytes
     * @return $this
     */
    public function setDescription($description) {
        $this->_description = Text::truncate($description, 500);
        return $this;
    }

    /**
     * @param $id
     *
     * @return $this
     */
    public function setCampaignId($id) {
        $this->_campaign_id = Text::truncate($id, 60);
        return $this;

    }

    /**
     * @param   string    $email
     * @param null|string $name
     * @param null|array  $subsitution_data
     *
     * @param null|array  $tags     Array of text labels associated with a recipient. Tags are available in Webhook events. Maximum number of tags - 10 per recipient,
     *                              100 system wide. Any tags over the limits are ignored.
     * @param null|array  $metadata Key/value pairs associated with a recipient. Metadata is available during events through the Webhooks and is provided to
     *                              the template engine. A maximum of 1000 bytes of merged metadata (transmission level + recipient level) is available
     *                              with recipient metadata taking
     *                              precedence over transmission metadata when there are conflicts.
     * @return $this
     */
    public function addRecipient($email, $name = NULL, $subsitution_data = NULL, $tags = NULL, $metadata = NULL) {
        $isDebug = Configure::read('debug');
        if ($isDebug) {
            $recipient = ['address' => ['email' => Configure::read('Config.adminEmail')]];
        } else {
            $recipient = ['address' => ['email' => $email]];
        }

        if (!empty($name)) {
            $recipient['address']['name'] = h($name);
        }
        if (!empty($subsitution_data)) {
            $recipient['substitution_data'] = $subsitution_data;

        }
        if (!empty($tags)) {
            $recipient['tags'] = $tags;
        }
        if (!empty($metadata)) {
            $recipient['metadata'] = $metadata;
        }
        $this->_receipients[] = $recipient;
        return $this;
    }


    /**
     * @param array $substitution_data
     *
     * @return $this
     */
    public function addSubtitutionData($substitution_data) {
        $this->_substitution_data = $substitution_data;
        return $this;
    }

    /**
     * @param ScidSparkPostRecipients $receipients
     */
    public function setReceipients($receipients): void {
        $this->_receipients = $receipients->getReceipients();
    }
}
