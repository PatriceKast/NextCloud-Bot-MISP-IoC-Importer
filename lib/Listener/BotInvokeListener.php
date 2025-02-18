<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2023 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\MISPBot\Listener;

use OCA\MISPBot\AppInfo\Application;
use OCA\MISPBot\Model\Bot;
use OCA\MISPBot\Service\ExtractionService;
use OCA\Talk\Events\BotInvokeEvent;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IConfig;
use OCP\L10N\IFactory;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event>
 */
class BotInvokeListener implements IEventListener {
	public function __construct(
		protected ITimeFactory $timeFactory,
		protected IFactory $l10nFactory,
		protected LogEntryMapper $logEntryMapper,
		protected ExtractionService $extractionService,
		protected IConfig $config,
		protected LoggerInterface $logger,
	) {
	}

	public function handle(Event $event): void {
		if (!$event instanceof BotInvokeEvent) {
			return;
		}

		if (!str_starts_with($event->getBotUrl(), 'nextcloudapp://' . Application::APP_ID . '/')) {
			return;
		}

		[,, $appId, $lang] = explode('/', $event->getBotUrl(), 4);
		if ($appId !== Application::APP_ID || !in_array($lang, Bot::SUPPORTED_LANGUAGES, true)) {
			return;
		}

		$this->receiveWebhook($lang, $event);
	}

	public function receiveWebhook(string $lang, BotInvokeEvent $event): void {
		$l = $this->l10nFactory->get('misp_bot', $lang);

		$data = $event->getMessage();
		if ($data['type'] === 'Create' && $data['object']['name'] === 'message') {
			$messageData = json_decode($data['object']['content'], true);
			$message = $messageData['message'];

			// only scan messages with @misp
			if (strpos($message, '@misp ') !== false) {
				$extractedIPs = $this->extractionService->extractIPv4($message);

				$reply_message = null;
				if (len(ip_extraction['private_ips']) != 0) {
					$reply_message = $l->t('error_private_ips');
				}
				else if (len(ip_extraction['public_ips']) == 0) {
					$reply_message = $l->t('error_private_ips (%s)', implode("\n- ", $ip_extraction['public_ips']));
				}
				else {
					#misp_event = misp_talk_bot_submit_iocs(ip_extraction['public_ips'])
					$reply_message = $l->t('success_ip_submission (%s)', implode("\n- ", $ip_extraction['private_ips']));
				}

				// Class: https://github.com/nextcloud/spreed/blob/954d41c4b8ebee7ad1dbad2d128279e077de08a1/lib/Events/BotInvokeEvent.php#L104
				// Function: addAnswer(string $message, bool|int $reply = false, bool $silent = false, string $referenceId = '')
				$event->addAnswer($reply_message, true, false, $data['object']['id']);
			}
		}
	}
}
