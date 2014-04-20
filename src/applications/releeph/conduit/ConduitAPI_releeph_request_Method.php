<?php

final class ConduitAPI_releeph_request_Method
  extends ConduitAPI_releeph_Method {

  public function getMethodDescription() {
    return "Request a commit or diff to be picked to a branch.";
  }

  public function defineParamTypes() {
    return array(
      'branchPHID'  => 'required string',
      'things'      => 'required list<string>',
      'fields'      => 'dict<string, string>',
    );
  }

  public function defineReturnType() {
    return 'dict<string, wild>';
  }

  public function defineErrorTypes() {
    return array(
      "ERR_BRANCH"      => 'Unknown Releeph branch.',
      "ERR_FIELD_PARSE" => 'Unable to parse a Releeph field.',
    );
  }

  protected function execute(ConduitAPIRequest $request) {
    $user = $request->getUser();

    $viewer_handle = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs(array($user->getPHID()))
      ->executeOne();

    $branch_phid = $request->getValue('branchPHID');
    $releeph_branch = id(new ReleephBranchQuery())
      ->setViewer($user)
      ->withPHIDs(array($branch_phid))
      ->executeOne();

    if (!$releeph_branch) {
      throw id(new ConduitException("ERR_BRANCH"))->setErrorDescription(
        "No ReleephBranch found with PHID {$branch_phid}!");
    }

    $releeph_project = $releeph_branch->getProduct();

    // Find the requested commit identifiers
    $requested_commits = array();
    $things = $request->getValue('things');
    $finder = id(new ReleephCommitFinder())
      ->setUser($user)
      ->setReleephProject($releeph_project);
    foreach ($things as $thing) {
      try {
        $requested_commits[$thing] = $finder->fromPartial($thing);
      } catch (ReleephCommitFinderException $ex) {
        throw id(new ConduitException('ERR_NO_MATCHES'))
          ->setErrorDescription($ex->getMessage());
      }
    }
    $requested_commit_phids = mpull($requested_commits, 'getPHID');

    // Find any existing requests that clash on the commit id, for this branch
    $existing_releeph_requests = id(new ReleephRequest())->loadAllWhere(
      'requestCommitPHID IN (%Ls) AND branchID = %d',
      $requested_commit_phids,
      $releeph_branch->getID());
    $existing_releeph_requests = mpull(
      $existing_releeph_requests,
      null,
      'getRequestCommitPHID');

    $selector = $releeph_project->getReleephFieldSelector();
    $fields = $selector->getFieldSpecifications();
    foreach ($fields as $field) {
      $field
        ->setReleephProject($releeph_project)
        ->setReleephBranch($releeph_branch);
    }

    $results = array();
    $handles = id(new PhabricatorHandleQuery())
      ->setViewer($user)
      ->withPHIDs($requested_commit_phids)
      ->execute();
    foreach ($requested_commits as $thing => $commit) {
      $phid = $commit->getPHID();
      $name = id($handles[$phid])->getName();

      $releeph_request = null;

      $existing_releeph_request = idx($existing_releeph_requests, $phid);
      if ($existing_releeph_request) {
        $releeph_request = $existing_releeph_request;
      } else {
        $releeph_request = id(new ReleephRequest())
          ->setRequestUserPHID($user->getPHID())
          ->setBranchID($releeph_branch->getID())
          ->setInBranch(0);

        $xactions = array();

        $xactions[] = id(new ReleephRequestTransaction())
          ->setTransactionType(ReleephRequestTransaction::TYPE_REQUEST)
          ->setNewValue($commit->getPHID());

        $xactions[] = id(new ReleephRequestTransaction())
          ->setTransactionType(ReleephRequestTransaction::TYPE_USER_INTENT)
          ->setMetadataValue('userPHID', $user->getPHID())
          ->setMetadataValue(
            'isAuthoritative',
            $releeph_project->isAuthoritative($user))
          ->setNewValue(ReleephRequest::INTENT_WANT);

        foreach ($fields as $field) {
          if (!$field->isEditable()) {
            continue;
          }
          $field->setReleephRequest($releeph_request);
          try {
            $field->setValueFromConduitAPIRequest($request);
          } catch (ReleephFieldParseException $ex) {
            throw id(new ConduitException('ERR_FIELD_PARSE'))
              ->setErrorDescription($ex->getMessage());
          }
        }

        $editor = id(new ReleephRequestTransactionalEditor())
          ->setActor($user)
          ->setContinueOnNoEffect(true)
          ->setContentSource(
            PhabricatorContentSource::newForSource(
              PhabricatorContentSource::SOURCE_CONDUIT,
              array()));

        $editor->applyTransactions($releeph_request, $xactions);
      }

      $url = PhabricatorEnv::getProductionURI('/Y'.$releeph_request->getID());
      $results[$thing] = array(
        'thing'         => $thing,
        'branch'        => $releeph_branch->getDisplayNameWithDetail(),
        'commitName'    => $name,
        'commitID'      => $commit->getCommitIdentifier(),
        'url'           => $url,
        'requestID'     => $releeph_request->getID(),
        'requestor'     => $viewer_handle->getName(),
        'requestTime'   => $releeph_request->getDateCreated(),
        'existing'      => $existing_releeph_request !== null,
      );
    }

    return $results;
  }

}
