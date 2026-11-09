@theme @theme_brandable @javascript
Feature: Publish the draft of the design
  In order to change the design of the site safely
  As an administrator
  I need to preview the draft, publish it and undo it, even without a Brandable account

  Background:
    Given the following config values are set as admin:
      | theme | brandable |
    And the Brandable draft changes the SCSS with ".card { border-radius: 1rem; }"
    And I log in as "admin"

  Scenario: Publish the draft with a version note
    When I visit "/theme/brandable/editor.php"
    Then I should see "Not published" in the "[data-region='status']" "css_element"
    And I click on "[data-action='publish']" "css_element"
    And I set the field "Version note" to "Rounder cards"
    And I click on "Publish" "button" in the ".modal-dialog" "css_element"
    And I should see "Published" in the "[data-region='status']" "css_element"

  Scenario: Discard the draft
    When I visit "/theme/brandable/editor.php"
    And I click on "[data-action='discard']" "css_element"
    And I click on "Discard" "button" in the ".modal-dialog" "css_element"
    Then I should see "Published" in the "[data-region='status']" "css_element"
