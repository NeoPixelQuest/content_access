<?php

/**
 * @file
 * Contains \Drupal\content_access\Form\ContentAccessPageForm.
 */

namespace Drupal\content_access\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Render\Element;
use Drupal\Core\Form\FormStateInterface;
use Drupal\node\Entity\Node;
use Drupal\Core\Template\Attribute;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Session\AccountInterface;

/**
 * Node Access settings form
 * @package Drupal\content_access\Form
 */
class ContentAccessPageForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'content_access_page';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, $node = NULL) {
    $node = Node::load($node);
    $defaults = array();

    foreach (_content_access_get_operations() as $op => $label) {
      $defaults[$op] = content_access_per_node_setting($op, $node);
    }

    // Get roles form
    module_load_include('admin.inc', 'content_access');
    content_access_role_based_form($form, $defaults, $node->getType());
    foreach (Element::children($form['per_role']) as $op) {
      if (!empty($form['per_role'][$op]['#type']) && $form['per_role'][$op]['#type'] == 'checkboxes') {
        $form['per_role'][$op]['#process'] = array(
          array('\Drupal\Core\Render\Element\Checkboxes', 'processCheckboxes'),
          array('\Drupal\content_access\Form\ContentAccessPageForm', 'disableCheckboxes'),
        );
      }
    }

    // Add an after_build handler that disables checkboxes, which are enforced by permissions.
    $build_info = $form_state->getBuildInfo();
    $build_info['files'][] = array(
      'module' => 'content_access',
      'type' => 'inc',
      'name' => 'content_access.admin'
    );
    $form_state->setBuildInfo($build_info);

    $form['per_role']['#after_build'] = array('content_access_force_permissions');

    // ACL form
    if (\Drupal::moduleHandler()->moduleExists('acl')) {
      // This is disabled when there is no node passed.
      $form['acl'] = array(
        '#type' => 'fieldset',
        '#title' => t('User access control lists'),
        '#description' => t('These settings allow you to grant access to specific users.'),
        '#collapsible' => TRUE,
        '#tree' => TRUE,
      );

      foreach (array('view', 'update', 'delete') as $op) {
        $acl_id = content_access_get_acl_id($node, $op);
        acl_node_add_acl($node->id(), $acl_id, (int) ($op == 'view'), (int) ($op == 'update'), (int) ($op == 'delete'), content_access_get_settings('priority', $node->getType()));

        $form['acl'][$op] = acl_edit_form($form_state, $acl_id, t('Grant !op access', array('!op' => $op)));
        $form['acl'][$op]['#collapsed'] = !isset($_POST['acl_' . $acl_id]) && !unserialize($form['acl'][$op]['user_list']['#default_value']);
      }
    }

    $storage['node'] = $node;
    $form_state->setStorage($storage);

    $form['reset'] = array(
      '#type' => 'submit',
      '#value' => t('Reset to defaults'),
      '#weight' => 10,
      '#submit' => array('content_access_page_reset'),
      '#access' => count(content_access_get_per_node_settings($node)) > 0,
    );
    $form['submit'] = array(
      '#type' => 'submit',
      '#value' => t('Submit'),
      '#weight' => 10,
    );

    // @todo not true anymore?
    // http://drupal.org/update/modules/6/7#hook_node_access_records
    if (!$node->isPublished()) {
      drupal_set_message(t("Warning: Your content is not published, so this settings are not taken into account as long as the content remains unpublished."), 'error');
    }

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $settings = array();
    $storage = $form_state->getStorage();
    $values = $form_state->getValues();
    $node = $storage['node'];

    foreach (_content_access_get_operations() as $op => $label) {
      // Set the settings so that further calls will return this settings.
      $filtered_values = array_filter($values[$op]);
      $settings[$op] = array_keys($filtered_values);
    }

    // Save per-node settings.
    content_access_save_per_node_settings($node, $settings);

    if (\Drupal::moduleHandler()->moduleExists('acl')) {
      foreach (array('view', 'update', 'delete') as $op) {
        $values = $form_state->getValues();
        acl_save_form($values['acl'][$op]);
        \Drupal::moduleHandler()->invokeAll('user_acl', $settings);
      }
    }

    // Apply new settings.
    \Drupal::entityManager()->getAccessControlHandler('node')->writeGrants($node);
    \Drupal::moduleHandler()->invokeAll('per_node', $settings);

    foreach (Cache::getBins() as $service_id => $cache_backend) {
      $cache_backend->deleteAll();
    }

    drupal_set_message(t('Your changes have been saved.'));
  }

  /**
   * Formapi #process callback, that disables checkboxes for roles without access to content
   */
  public static function disableCheckboxes(&$element, FormStateInterface $form_state, &$complete_form) {
    $access_roles = content_access_get_permission_access('access content');
    $admin_roles = content_access_get_permission_access('administer nodes');

    foreach (Element::children($element) as $key) {
      if (!in_array($key, $access_roles) &&
        $key == AccountInterface::ANONYMOUS_ROLE &&
        !in_array(AccountInterface::AUTHENTICATED_ROLE, $access_roles)
      ) {
        $element[$key]['#disabled'] = TRUE;
        $element[$key]['#default_value'] = FALSE;
        $element[$key]['#prefix'] = '<span' . new Attribute(array('title' => t("This role is lacking the permission '@perm', so it has no access.", array('@perm' => t('access content'))))) . '>';
        $element[$key]['#suffix'] = "</span>";
      }
      elseif (in_array($key, $admin_roles) || ($key != AccountInterface::ANONYMOUS_ROLE && in_array(AccountInterface::AUTHENTICATED_ROLE, $admin_roles))) {
        // Fix the checkbox to be enabled for users with administer node privileges
        $element[$key]['#disabled'] = TRUE;
        $element[$key]['#default_value'] = TRUE;
        $element[$key]['#prefix'] = '<span' . new Attribute(array('title' => t("This role has '@perm' permission, so access is granted.", array('@perm' => t('administer nodes'))))) . '>';
        $element[$key]['#suffix'] = "</span>";
      }
    }

    return $element;
  }
}
