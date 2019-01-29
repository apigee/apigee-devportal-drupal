<?php

/**
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License version 2 as published by the
 * Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public
 * License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc., 51
 * Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_edge_teams\Functional;

use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\Tests\apigee_edge\Traits\EntityUtilsTrait;
use Drupal\Tests\field_ui\Traits\FieldUiTestTrait;

/**
 * Team and team app entity UI tests.
 *
 * @group apigee_edge
 * @group apigee_edge_teams
 */
class UITest extends ApigeeEdgeTeamsFunctionalTestBase {

  use EntityUtilsTrait;
  use FieldUiTestTrait;

  /**
   * The team entity storage.
   *
   * @var \Drupal\apigee_edge_teams\Entity\Storage\TeamStorageInterface
   */
  protected $teamStorage;

  /**
   * Default user.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $account;

  /**
   * Other user to test team membership related UIs.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $otherAccount;

  /**
   * Team entity to test.
   *
   * @var \Drupal\apigee_edge_teams\Entity\TeamInterface
   */
  protected $team;

  /**
   * Product to test team app entity.
   *
   * @var \Drupal\apigee_edge\Entity\ApiProductInterface
   */
  protected $product;

  /**
   * Custom field properties and data of teams and team apps.
   *
   * @var array
   */
  protected $fields;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->teamStorage = $this->container->get('entity_type.manager')->getStorage('team');

    $this->installExtraModules(['block']);
    $this->drupalPlaceBlock('system_breadcrumb_block');
    $this->drupalPlaceBlock('local_tasks_block');
    $this->drupalPlaceBlock('local_actions_block');

    $this->product = $this->createProduct();
    $this->account = $this->createAccount([
      'administer team',
    ]);
    $this->otherAccount = $this->createAccount();

    $this->addFieldsToEntities();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown() {
    try {
      if ($this->account !== NULL) {
        $this->account->delete();
      }
    }
    catch (\Exception $exception) {
      $this->logException($exception);
    }

    try {
      if ($this->otherAccount !== NULL) {
        $this->otherAccount->delete();
      }
    }
    catch (\Exception $exception) {
      $this->logException($exception);
    }

    try {
      if ($this->team !== NULL) {
        $this->teamStorage->delete([$this->team]);
      }
    }
    catch (\Exception $exception) {
      $this->logException($exception);
    }

    try {
      if ($this->product !== NULL) {
        $this->product->delete();
      }
    }
    catch (\Exception $exception) {
      $this->logException($exception);
    }

    parent::tearDown();
  }

  /**
   * Tests the UI of the team and team app entities.
   */
  public function testUi() {
    $this->drupalLogin($this->account);
    // Open team collection page.
    $this->drupalGet(Url::fromRoute('entity.team.collection'));

    // Create a new team and check whether the link to the team is visible on
    // the listing page.
    $this->clickLink('Add team');
    $team_name = $team_display_name = $this->getRandomGenerator()->word(10);
    $this->submitForm([
      'name' => $team_name,
      'displayName[0][value]' => $team_display_name,
      'field_integer[0][value]' => $this->fields['integer']['data'],
      'field_email[0][value]' => $this->fields['email']['data'],
    ], 'Add team');
    $this->team = $this->teamStorage->load($team_display_name);

    // The team's display name and field values are visible on the canonical
    // page.
    $this->clickLink($team_display_name);
    $this->assertSession()->pageTextContains($team_display_name);
    $this->assertSession()->pageTextContains($this->fields['integer']['data']);
    $this->assertSession()->pageTextContains($this->fields['email']['data']);

    // Update the team and check whether the updated name and field values are
    // visible on the listing and canonical pages.
    $this->clickLink('Edit');
    $team_modified_display_name = $this->randomMachineName();
    $this->submitForm([
      'displayName[0][value]' => $team_modified_display_name,
      'field_integer[0][value]' => $this->fields['integer']['data_edited'],
      'field_email[0][value]' => $this->fields['email']['data_edited'],
    ], 'Save team');

    $this->clickLink($team_modified_display_name);
    $this->assertSession()->pageTextContains($team_modified_display_name);
    $this->assertSession()->pageTextContains($this->fields['integer']['data_edited']);
    $this->assertSession()->pageTextContains($this->fields['email']['data_edited']);

    // Add the other user as a member to the team.
    $this->clickLink('Members');
    $this->assertSession()->pageTextContains("{$this->account->get('first_name')->value} {$this->account->get('last_name')->value}");
    $this->clickLink('Add members');
    $this->submitForm([
      'developers' => "{$this->otherAccount->get('first_name')->value} {$this->otherAccount->get('last_name')->value} ({$this->otherAccount->id()})",
    ], 'Add members');
    $this->assertSession()->pageTextContains("{$this->account->get('first_name')->value} {$this->account->get('last_name')->value}");
    $this->assertSession()->pageTextContains("{$this->otherAccount->get('first_name')->value} {$this->otherAccount->get('last_name')->value}");

    // Team members have access to every team app and membership operations.
    $this->drupalPostForm(Url::fromRoute('apigee_edge_teams.settings.team.member_permissions'), [
      'permissions[team][manage_members]' => TRUE,
      'permissions[app][create]' => TRUE,
      'permissions[app][update]' => TRUE,
      'permissions[app][delete]' => TRUE,
      'permissions[app][analytics]' => TRUE,
    ], 'Save configuration');

    // Login with the other user and ensure that it is member of the team.
    $this->drupalLogin($this->otherAccount);
    $this->drupalGet(Url::fromRoute('entity.team.collection'));
    $this->clickLink($team_modified_display_name);

    // Add a new team app to the team.
    $this->clickLink('Team Apps');
    $this->assertSession()->pageTextContains('There are no Team Apps yet.');
    $this->clickLink('Add team app');

    $team_app_1_name = $this->getRandomGenerator()->word(10);
    $team_app_1_display_name = $this->getRandomGenerator()->word(10);
    $this->submitForm([
      'name' => $team_app_1_name,
      'displayName[0][value]' => $team_app_1_display_name,
      'field_integer[0][value]' => $this->fields['integer']['data'],
      'field_email[0][value]' => $this->fields['email']['data'],
      "api_products[{$this->product->getName()}]" => $this->product->getName(),
    ], 'Add team app');
    $this->assertSession()->pageTextContains('Team App has been successfully created.');
    $this->clickLink($team_app_1_display_name);
    $this->assertSession()->pageTextContains($team_app_1_display_name);
    $this->assertSession()->pageTextContains($this->fields['integer']['data']);
    $this->assertSession()->pageTextContains($this->fields['email']['data']);

    // Update the previously created team app and check the updated values.
    $this->clickLink('Edit');
    $team_app_1_modified_display_name = $this->randomMachineName();
    $this->submitForm([
      'displayName[0][value]' => $team_app_1_modified_display_name,
      'field_integer[0][value]' => $this->fields['integer']['data_edited'],
      'field_email[0][value]' => $this->fields['email']['data_edited'],
    ], 'Save');
    $this->clickLink($team_app_1_modified_display_name);
    $this->assertSession()->pageTextContains($team_app_1_modified_display_name);
    $this->assertSession()->pageTextContains($this->fields['integer']['data_edited']);
    $this->assertSession()->pageTextContains($this->fields['email']['data_edited']);

    // Has access to team app analytics.
    $this->clickLink('Analytics');
    $this->assertSession()->pageTextContains('No performance data is available for the criteria you supplied.');

    // Login with the default user, the created team app should be visible on
    // the team app administer collection page (/team-apps).
    $this->drupalLogin($this->account);
    $this->drupalGet(Url::fromRoute('entity.team_app.collection'));
    $this->assertSession()->linkExists($team_modified_display_name);
    $this->assertSession()->linkExists($team_app_1_modified_display_name);

    // Create a new team app using the team app add form for admins.
    $this->clickLink('Add team app');
    $team_app_2_name = $this->getRandomGenerator()->word(10);
    $team_app_2_display_name = $this->getRandomGenerator()->word(10);
    $this->submitForm([
      'owner' => $team_name,
      'name' => $team_app_2_name,
      'displayName[0][value]' => $team_app_2_display_name,
      "api_products[{$this->product->getName()}]" => $this->product->getName(),
    ], 'Add team app');
    $this->assertSession()->pageTextContains('Team App has been successfully created.');
    $this->assertSession()->linkExists($team_app_2_display_name);

    // Login with the other user and ensure that both team apps are visible on
    // the team app collection by team page.
    $this->drupalLogin($this->otherAccount);
    $this->drupalGet(Url::fromRoute('entity.team.collection'));
    $this->clickLink($team_modified_display_name);
    $this->clickLink('Team Apps');
    $this->assertSession()->linkExists($team_app_1_modified_display_name);
    $this->assertSession()->linkExists($team_app_2_display_name);

    // Try to delete the first team app without verification code then with a
    // correct one.
    $this->clickLink($team_app_1_modified_display_name);
    $this->clickLink('Delete');
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains('The name does not match the team app you are attempting to delete.');

    $this->submitForm([
      'verification_code' => $team_app_1_name,
    ], 'Delete');
    $this->assertSession()->pageTextContains("The {$team_app_1_modified_display_name} team app has been deleted.");
    $this->assertSession()->linkNotExists($team_app_1_modified_display_name);
    $this->assertSession()->linkExists($team_app_2_display_name);

    // Remove the other user from the team's member list.
    $this->drupalLogin($this->account);
    $this->drupalGet(Url::fromRoute('entity.team_app.collection'));
    $this->clickLink($team_modified_display_name);
    $this->clickLink('Members');
    $this->getSession()->getPage()->findById((Html::getUniqueId($this->otherAccount->getEmail())))->clickLink('Remove');
    $this->submitForm([], 'Confirm');

    // The other user's team listing page is empty..
    $this->drupalLogin($this->otherAccount);
    $this->drupalGet(Url::fromRoute('entity.team.collection'));
    $this->assertSession()->pageTextContains('There are no Teams yet.');

    // Delete the team with the default user.
    $this->drupalLogin($this->account);
    $this->drupalGet(Url::fromRoute('entity.team.collection'));
    $this->clickLink($team_modified_display_name);
    // Try to delete the team without verification code.
    $this->clickLink('Delete');
    $this->submitForm([], 'Delete');
    $this->assertSession()->pageTextContains('The name does not match the team you are attempting to delete.');

    // Delete the team using correct verification code.
    $this->submitForm([
      'verification_code' => $team_name,
    ], 'Delete');

    // The team is not in the list.
    $this->assertSession()->pageTextContains("The {$team_modified_display_name} team has been deleted.");
    $this->assertSession()->linkNotExists($team_modified_display_name);

    // The team listing page is empty of the other user.
    $this->drupalLogin($this->otherAccount);
    $this->drupalGet(Url::fromRoute('entity.team.collection'));
    $this->assertSession()->pageTextContains('There are no Teams yet.');
  }

  /**
   * Tests the team entity label modifications.
   */
  public function testTeamAndTeamAppLabel() {
    $this->drupalLogin($this->rootUser);
    $this->changeEntityAliasesAndValidate('team', 'apigee_edge_teams.settings.team');
    $this->changeEntityAliasesAndValidate('team_app', 'apigee_edge_teams.settings.team_app');
  }

  /**
   * Create custom fields for team and team app.
   */
  private function addFieldsToEntities() {
    $this->drupalLogin($this->rootUser);
    $this->fields = [
      'integer' => [
        'type' => 'integer',
        'data' => rand(),
        'data_edited' => rand(),
      ],
      'email' => [
        'type' => 'email',
        'data' => $this->randomMachineName() . '@example.com',
        'data_edited' => $this->randomMachineName() . '@example.com',
      ],
    ];

    // Add fields to team and team app.
    $add_field_paths = [
      Url::fromRoute('apigee_edge_teams.settings.team')->toString(),
      Url::fromRoute('apigee_edge_teams.settings.team_app')->toString(),
    ];
    foreach ($add_field_paths as $add_field_path) {
      foreach ($this->fields as $name => $data) {
        $this->fieldUIAddNewField(
          $add_field_path,
          $name, strtoupper($name),
          $data['type'],
          ($data['settings'] ?? []) + [
            'cardinality' => -1,
          ],
          []
        );
      }
    }
  }

}