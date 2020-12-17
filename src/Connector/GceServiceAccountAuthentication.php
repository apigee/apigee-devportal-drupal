<?php

/**
 * Copyright 2020 Google Inc.
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

namespace Drupal\apigee_edge\Connector;

use Apigee\Edge\Api\Management\Controller\OrganizationController;
use Apigee\Edge\ClientInterface;
use Apigee\Edge\HttpClient\Plugin\Authentication\GceServiceAccount;

/**
 * Decorator for Hybrid authentication plugin.
 */
class GceServiceAccountAuthentication extends GceServiceAccount {

  /**
   * {@inheritdoc}
   */
  protected function authClient(): ClientInterface {
    /** @var \Drupal\apigee_edge\SDKConnectorInterface $sdk_connector */
    $sdk_connector = \Drupal::service('apigee_edge.sdk_connector');
    return $sdk_connector->buildClient($this->getAuthHeader(), $this->getAuthServer());
  }

  /**
   * Returns the list of all Organizations available to the service account.
   *
   * @return \Apigee\Edge\Api\Management\Entity\OrganizationInterface[]
   */
  public function getAuthorizedOrganizations() {
    /** @var \Drupal\apigee_edge\SDKConnectorInterface $sdk_connector */
    $sdk_connector = \Drupal::service('apigee_edge.sdk_connector');
    $client = $sdk_connector->buildClient($this, ClientInterface::HYBRID_ENDPOINT);
    $controller = new OrganizationController($client);
    return $controller->getEntities();
  }

}
