@theme @theme_brandable @javascript
Feature: New versions of the plugin
  In order to keep Brandable up to date
  As an administrator
  I need the editor to tell me about a new version, with its release notes

  Scenario: A new version can be hidden until the next one
    Given Brandable announces the plugin release "9.0.0"
    And I log in as "admin"
    When I visit "/theme/brandable/editor.php"
    Then I should see "Brandable 9.0.0 is available"
    And I click on "Hide until the next version" "button"
    And I reload the page
    And I should not see "Brandable 9.0.0 is available"

  Scenario: A security update cannot be hidden
    Given Brandable announces the plugin release "9.0.1" as a security update
    And I log in as "admin"
    When I visit "/theme/brandable/editor.php"
    Then I should see "Brandable 9.0.1 is available"
    And "Hide until the next version" "button" should not exist
