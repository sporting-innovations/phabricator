<?php

final class PhabricatorPeopleProfileController
  extends PhabricatorPeopleController {

  private $username;

  public function shouldRequireAdmin() {
    return false;
  }

  public function willProcessRequest(array $data) {
    $this->username = idx($data, 'username');
  }

  public function processRequest() {
    $viewer = $this->getRequest()->getUser();

    $user = id(new PhabricatorPeopleQuery())
      ->setViewer($viewer)
      ->withUsernames(array($this->username))
      ->executeOne();
    if (!$user) {
      return new Aphront404Response();
    }

    require_celerity_resource('phabricator-profile-css');

    $profile = $user->loadUserProfile();
    $username = phutil_escape_uri($user->getUserName());

    $picture = $user->loadProfileImageURI();

    $header = id(new PhabricatorHeaderView())
      ->setHeader($user->getUserName().' ('.$user->getRealName().')')
      ->setSubheader($profile->getTitle())
      ->setImage($picture);

    if ($user->getIsDisabled()) {
      $header->addTag(
        id(new PhabricatorTagView())
          ->setType(PhabricatorTagView::TYPE_STATE)
          ->setBackgroundColor(PhabricatorTagView::COLOR_GREY)
          ->setName(pht('Disabled')));
    }

    if ($user->getIsAdmin()) {
      $header->addTag(
        id(new PhabricatorTagView())
          ->setType(PhabricatorTagView::TYPE_STATE)
          ->setBackgroundColor(PhabricatorTagView::COLOR_RED)
          ->setName(pht('Administrator')));
    }

    if ($user->getIsSystemAgent()) {
      $header->addTag(
        id(new PhabricatorTagView())
          ->setType(PhabricatorTagView::TYPE_STATE)
          ->setBackgroundColor(PhabricatorTagView::COLOR_BLUE)
          ->setName(pht('Bot')));
    }


    $statuses = id(new PhabricatorUserStatus())
      ->loadCurrentStatuses(array($user->getPHID()));
    if ($statuses) {
      $header->addTag(
        id(new PhabricatorTagView())
          ->setType(PhabricatorTagView::TYPE_STATE)
          ->setBackgroundColor(PhabricatorTagView::COLOR_ORANGE)
          ->setName(head($statuses)->getTerseSummary($viewer)));
    }

    $actions = id(new PhabricatorActionListView())
      ->setObject($user)
      ->setUser($viewer);

    $can_edit = ($user->getPHID() == $viewer->getPHID());

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('edit')
        ->setName(pht('Edit Profile'))
        ->setHref($this->getApplicationURI('editprofile/'.$user->getID().'/'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    $actions->addAction(
      id(new PhabricatorActionView())
        ->setIcon('image')
        ->setName(pht('Edit Profile Picture'))
        ->setHref($this->getApplicationURI('picture/'.$user->getID().'/'))
        ->setDisabled(!$can_edit)
        ->setWorkflow(!$can_edit));

    if ($viewer->getIsAdmin()) {
      $actions->addAction(
        id(new PhabricatorActionView())
          ->setIcon('blame')
          ->setName(pht('Administrate User'))
          ->setHref($this->getApplicationURI('edit/'.$user->getID().'/')));
    }

    $properties = $this->buildPropertyView($user);

    $crumbs = $this->buildApplicationCrumbs();
    $crumbs->addCrumb(
      id(new PhabricatorCrumbView())
        ->setName($user->getUsername()));
    $feed = $this->renderUserFeed($user);

    return $this->buildApplicationPage(
      array(
        $crumbs,
        $header,
        $actions,
        $properties,
        $feed,
      ),
      array(
        'title' => $user->getUsername(),
        'device' => true,
        'dust' => true,
      ));
  }

  private function buildPropertyView(PhabricatorUser $user) {
    $viewer = $this->getRequest()->getUser();

    $view = id(new PhabricatorPropertyListView())
      ->setUser($viewer)
      ->setObject($user);

    $fields = PhabricatorCustomField::getObjectFields(
      $user,
      PhabricatorCustomField::ROLE_VIEW);

    foreach ($fields as $field) {
      $field->setViewer($viewer);
    }

    $view->applyCustomFields($fields);

    return $view;
  }

  private function renderUserFeed(PhabricatorUser $user) {
    $viewer = $this->getRequest()->getUser();

    $query = new PhabricatorFeedQuery();
    $query->setFilterPHIDs(
      array(
        $user->getPHID(),
      ));
    $query->setLimit(100);
    $query->setViewer($viewer);
    $stories = $query->execute();

    $builder = new PhabricatorFeedBuilder($stories);
    $builder->setUser($viewer);
    $view = $builder->buildView();

    return hsprintf(
      '<div class="profile-feed profile-wrap-responsive">
        %s
      </div>',
      $view->render());
  }
}
