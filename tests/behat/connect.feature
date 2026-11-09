@theme @theme_brandable
Feature: Connect the site to Brandable
  In order to know what the site shares before connecting it
  As an administrator
  I need to see what Brandable receives, its terms and its privacy policy

  Scenario: The connection page lists what Brandable receives
    Given I log in as "admin"
    When I visit "/theme/brandable/connect.php"
    Then I should see "What Brandable receives"
    And I should see "With each prompt: the prompt"
    And "Terms of service" "link" should exist
    And "Privacy policy" "link" should exist
    And "Connect to Brandable" "button" should exist

  @javascript
  Scenario: The editor of a site not connected offers the free plan
    Given I log in as "admin"
    When I visit "/theme/brandable/editor.php"
    Then I should see "Create your free account: 30 credits offered every month"
    And I should see "What Brandable receives" in the "[data-region='connect']" "css_element"
    And "Terms of service" "link" should exist in the "[data-region='connect']" "css_element"
