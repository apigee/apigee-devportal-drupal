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

namespace Drupal\apigee_edge_teams\Entity;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityHandlerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityTypeInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Access handler for Team App entities.
 */
final class TeamAppAccessHandler extends EntityAccessControlHandler implements EntityHandlerInterface {

  /**
   * Name of the config object that contains the member permissions.
   */
  public const MEMBER_PERMISSIONS_CONFIG_NAME = 'apigee_edge_teams.team_settings';

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  private $config;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  private $entityTypeManager;

  /**
   * The developer storage.
   *
   * @var \Drupal\apigee_edge\Entity\Storage\DeveloperStorageInterface
   */
  private $developerStorage;

  /**
   * The current route match.
   *
   * @var \Drupal\Core\Routing\RouteMatchInterface
   */
  private $routeMatch;

  /**
   * TeamAppAccessHandler constructor.
   *
   * @param \Drupal\Core\Entity\EntityTypeInterface $entity_type
   *   The entity type.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config
   *   The config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Routing\RouteMatchInterface $route_match
   *   The current route match.
   */
  public function __construct(EntityTypeInterface $entity_type, ConfigFactoryInterface $config, EntityTypeManagerInterface $entity_type_manager, RouteMatchInterface $route_match) {
    parent::__construct($entity_type);
    $this->config = $config;
    $this->entityTypeManager = $entity_type_manager;
    $this->developerStorage = $entity_type_manager->getStorage('developer');
    $this->routeMatch = $route_match;
  }

  /**
   * {@inheritdoc}
   */
  public static function createInstance(ContainerInterface $container, EntityTypeInterface $entity_type) {
    return new static(
      $entity_type,
      $container->get('config.factory'),
      $container->get('entity_type.manager'),
      $container->get('current_route_match')
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account) {
    /** @var \Drupal\apigee_edge_teams\Entity\TeamAppInterface $entity */
    $result = parent::checkAccess($entity, $operation, $account);

    if ($result->isNeutral()) {
      $result = $this->checkAccessByPermissions($account);
      if ($result->isNeutral()) {
        /** @var \Drupal\apigee_edge_teams\Entity\TeamInterface $team */
        $team = $this->entityTypeManager->getStorage('team')->load($entity->getCompanyName());
        if ($team) {
          // All members of a team can view team apps owned by the team.
          if ($operation === 'view') {
            $result = $this->accessResultByTeamMembership($team, $account);
          }
          else {
            $result = $this->checkAccessByTeamMembership($team, $operation, $account);
          }
        }
        else {
          // Probably this could never happen...
          $result = AccessResult::neutral("The team ({$entity->getCompanyName()}) that the team app ({$entity->getAppId()}) belongs does not exist.");
        }
      }
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL) {
    $result = parent::checkCreateAccess($account, $context, $entity_bundle);

    if ($result->isNeutral()) {
      // Applies to "add-form" link template of Team App entity.
      $result = $this->checkAccessByPermissions($account);

      if ($result->isNeutral()) {
        // Applies to "add-form-for-team" link template of Team App entity.
        $team = $this->routeMatch->getParameter('team');
        if ($team) {
          $result = $this->checkAccessByTeamMembership($team, 'create', $account);
        }
        else {
          $result = AccessResult::neutral("Team parameter has not been found in {$this->routeMatch->getRouteObject()->getPath()} path.");
        }
      }
    }

    return $result;
  }

  /**
   * Performs access check based on a user's site-wide permissions.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function checkAccessByPermissions(AccountInterface $account): AccessResultInterface {
    $permissions = [
      TeamAppPermissionProvider::MANAGE_TEAM_APPS_PERMISSION,
    ];
    if ($this->entityType->getAdminPermission()) {
      $permissions[] = $this->entityType->getAdminPermission();
    }
    return AccessResult::allowedIfHasPermissions($account, $permissions);
  }

  /**
   * Performs access check based on a user's team-level permissions.
   *
   * @param \Drupal\apigee_edge_teams\Entity\TeamInterface $team
   *   The team that owns the app.
   * @param string $operation
   *   The entity operation on a team app: create, delete, update or analytics.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user for which to check access.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function checkAccessByTeamMembership(TeamInterface $team, string $operation, AccountInterface $account): AccessResultInterface {
    if (!in_array($operation, ['create', 'delete', 'update', 'analytics'])) {
      return AccessResult::neutral("Team membership based access check does not support {$operation} operation on apps.");
    }

    if ($this->config->get(static::MEMBER_PERMISSIONS_CONFIG_NAME)->get("members_can_access_app_{$operation}")) {
      $result = $this->accessResultByTeamMembership($team, $account);
    }
    else {
      $result = AccessResult::neutral("Current configuration does not allow team members to perform {$operation} operation on team apps.")
        ->addCacheTags(['config:' . static::MEMBER_PERMISSIONS_CONFIG_NAME]);
    }
    // Ensure that access is re-evaluated when the team or the developer
    // entity changes.
    $result->addCacheableDependency($team);
    return $result;
  }

  /**
   * Returns an access result base on whether a user is member of a team or not.
   *
   * @param \Drupal\apigee_edge_teams\Entity\TeamInterface $entity
   *   The team.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  private function accessResultByTeamMembership(TeamInterface $entity, AccountInterface $account): AccessResultInterface {
    if ($this->isMember($entity, $account)) {
      $result = AccessResult::allowed();
    }
    else {
      $result = AccessResult::neutral("{$account->getDisplayName()} is not member of {$entity->label()} team.");
    }
    $this->processAccessResult($result, $account);
    return $result;
  }

  /**
   * Processes access result before it gets returned.
   *
   * Adds necessary cache tags to the access result object.
   *
   * @param \Drupal\Core\Access\AccessResult $result
   *   The access result to be altered if needed.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user for which to access check has happened.
   */
  private function processAccessResult(AccessResult $result, AccountInterface $account) {
    // Ensure that access is re-evaluated when developer entity or config
    // changes.
    $result->addCacheTags(['config:' . static::MEMBER_PERMISSIONS_CONFIG_NAME]);
    $developer = $this->developerStorage->load($account->getEmail());
    if ($developer) {
      $result->addCacheableDependency($developer);
    }
  }

  /**
   * Checks whether a user is member of a team.
   *
   * @param \Drupal\apigee_edge_teams\Entity\TeamInterface $entity
   *   The team.
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The user.
   *
   * @return bool
   *   TRUE if the user is member of the team, FALSE otherwise.
   */
  private function isMember(TeamInterface $entity, AccountInterface $account): bool {
    /** @var \Drupal\apigee_edge\Entity\DeveloperInterface|null $developer */
    $developer = $this->developerStorage->load($account->getEmail());
    return $developer && in_array($entity->id(), $developer->getCompanies());
  }

}