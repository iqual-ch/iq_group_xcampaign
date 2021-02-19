<?php

namespace Drupal\iq_group_xcampaign\EventSubscriber;

use Drupal\iq_group\Event\IqGroupEvent;
use Drupal\iq_group\IqGroupEvents;
use Drupal\iq_group\XCampaignEvents;
use Drupal\iq_group\Event\XCampaignEvent;
use Drupal\xcampaign_api\XCampaignApiServiceInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;


/**
 * Event subscriber to handle xcampaign events dispatched by iq_group module.
 */
class XCampaignSubscriber implements EventSubscriberInterface {

  /**
   * @var \Drupal\xcampaign_api\XCampaignApiServiceInterface
   */
  protected $xcampaignApiService;

  /**
   * OrderReceiptSubscriber constructor.
   *
   * @param \Drupal\xcampaign_api\XCampaignApiServiceInterface $xcampaign_api_service
   */
  public function __construct(XCampaignApiServiceInterface $xcampaign_api_service) {
    $this->xcampaignApiService = $xcampaign_api_service;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents() {
    return [
      IqGroupEvents::USER_PROFILE_UPDATE => [['updateXCampaignContact', 300]],
      IqGroupEvents::USER_PROFILE_DELETE => [['deleteXCampaignContact', 300]],
    ];
  }

  /**
   * Update a XCampaign contact.
   *
   * @param \Drupal\iq_group\Event\IqGroupEvent $event
   *   The event.
   */
  public function updateXCampaignContact(IqGroupEvent $event) {
    if ($event && $event->getUser()->id()) {
      \Drupal::logger('iq_group_xcampaign')->notice('XCampaign update event triggered for ' . $event->getUser()->id());

      /** @var \Drupal\user\UserInterface $user */
      $user = $event->getUser();
      if ($user->status->value) {
        $xcampaign_id = $user->field_iq_group_xcampaign_id->value;

        $email = $user->getEmail();
        $profile_data = [
          'user_id' => $user->id(),
          'email' => $email,
          'langcode' => $user->getPreferredLangcode(),
          'ip_address' => \Drupal::request()->getClientIp(),
          "first_name" => reset($user->get('field_iq_user_base_address')->getValue())['given_name'],
          "last_name" => reset($user->get('field_iq_user_base_address')->getValue())['family_name'],
          'token' => $user->field_iq_group_user_token->value,
          "address" => reset($user->get('field_iq_user_base_address')->getValue())['address_line1'],
          "postcode" => reset($user->get('field_iq_user_base_address')->getValue())['postal_code'],
          "city" => reset($user->get('field_iq_user_base_address')->getValue())['locality']
        ];

        if ($user->hasField('field_gcb_custom_birth_date') && !$user->get('field_gcb_custom_birth_date')->isEmpty()) {
          $profile_data["birth_date"] = $user->field_gcb_custom_birth_date->value;
        }
        if ($user->hasField('field_iq_group_preferences') && !$user->get('field_iq_group_preferences')->isEmpty()) {
          $profile_data["preferences"] = array_filter(array_column($user->field_iq_group_preferences->getValue(), 'target_id'));
        }
        if ($user->hasField('field_iq_group_xcampaign_id') && !empty($user->get('field_iq_group_xcampaign_id')->getValue())) {
          $profile_data['xcampaign_id'] = $user->field_iq_group_xcampaign_id->value;
          $this->xcampaignApiService->editContact($profile_data['xcampaign_id'], $profile_data);
        } else {
          $xcampaign_id = $this->xcampaignApiService->createContact($email, $profile_data);
          $user->set('field_iq_group_xcampaign_id', $xcampaign_id);
        }
        // Delete from blacklist - because the user is active.
        $this->xcampaignApiService->deleteFromBlacklist($email);
      }
      else if (!empty($user->field_iq_group_xcampaign_id->value)){
        $email = $user->getEmail();
        // Update blacklist if the user is blocked and there he is registered on xCampaign.
        $this->xcampaignApiService->updateBlacklist($email);
      }

    }
  }

   /**
   * Delete a XCampaign contact.
   *
   * @param \Drupal\iq_group\Event\IqGroupEvent $event
   *   The event.
   */
  public function deleteXCampaignContact(IqGroupEvent $event) {
    if ($event && $event->getUser()->id()) {
      \Drupal::logger('iq_group_xcampaign')->notice('XCampaign delete event triggered for ' . $event->getUser()->id());

      $user = $event->getUser();

      $xcampaign_id = $user->field_iq_group_xcampaign_id->value;

      if (!empty($xcampaign_id) || $xcampaign_id != 0) {
        $contact = $this->xcampaignApiService->deleteContact($xcampaign_id);
      }
    }
  }
}
