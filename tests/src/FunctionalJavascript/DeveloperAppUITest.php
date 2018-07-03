<?php

/**
 * Copyright 2018 Google Inc.
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * version 2 as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 */

namespace Drupal\Tests\apigee_edge\FunctionalJavascript;

use Drupal\Tests\apigee_edge\Functional\DeveloperAppUITestTrait;

/**
 * Developer app UI Javascript tests.
 *
 * @group apigee_edge
 * @group apigee_edge_javascript
 * @group apigee_edge_developer_app
 */
class DeveloperAppUITest extends ApigeeEdgeFunctionalJavascriptTestBase {

  use DeveloperAppUITestTrait;

  /**
   * Tests callback url validation on the client-side.
   */
  public function testCallbackUrlValidationClientSide() {
    $isValidInput = function () : bool {
      return $this->getSession()->evaluateScript('document.getElementById("edit-callbackurl-0-value").checkValidity()');
    };
    $checkValidationMessage = function (string $expected) : void {
      $this->assertEquals($expected, $this->getSession()->evaluateScript('document.getElementById("edit-callbackurl-0-value").validationMessage'));
    };

    // Override default configuration.
    $pattern_description = 'It must be https://example.com';
    $this->config('apigee_edge.developer_app_settings')
      ->set('callback_url_pattern', '^https:\/\/example.com')
      ->set('callback_url_pattern_error_message', $pattern_description)
      ->save();

    $this->products[] = $product = $this->createProduct();
    $app = $this->createDeveloperApp([
      'name' => $this->randomMachineName(),
      'displayName' => $this->randomString(),
      'callbackUrl' => $this->randomMachineName(),
    ], $this->account, [$product->id()]);
    $app_edit_url = $app->toUrl('edit-form-for-developer');

    $this->drupalGet($app_edit_url);
    $this->drupalPostForm($app_edit_url, [], t('Save'));
    $this->createScreenshot('DeveloperAppUITest-' . __FUNCTION__);
    $this->assertFalse($isValidInput());
    $checkValidationMessage('Please enter a URL.');
    $this->drupalPostForm($app_edit_url, ['callbackUrl[0][value]' => 'http://example.com'], t('Save'));
    $this->createScreenshot('DeveloperAppUITest-' . __FUNCTION__);
    $this->assertFalse($isValidInput());
    // The format in Firefox is different, it is only one line:
    // "Please match the requested format: {$pattern_description}.".
    $checkValidationMessage('Please match the requested format.');
    $this->assertEquals($pattern_description, $this->getSession()->evaluateScript('document.getElementById("edit-callbackurl-0-value").title'));
    $this->drupalPostForm($app_edit_url, ['callbackUrl[0][value]' => 'https://example.com'], t('Save'));
    $this->assertSession()->pageTextContains('Developer App details have been successfully updated.');
    $this->assertSession()->pageTextContains('https://example.com');
  }

}