@theme @theme_brandable
Feature: Generated JavaScript, off by default
  In order to keep control of the code that runs on the site
  As an administrator
  I need the generated JavaScript to be off until I enable it, and a switch that stops every module

  Scenario: The generated JavaScript is off by default
    Given I log in as "admin"
    When I visit "/theme/brandable/jsconsent.php"
    Then I should see "The generated JavaScript is off."

  Scenario: Every module can be stopped at once
    Given the following config values are set as admin:
      | brandable_js | 1 | theme_brandable |
    And I log in as "admin"
    When I visit "/theme/brandable/jsconsent.php"
    And I press "Stop every module"
    Then I should see "Every generated module is stopped."
    And I press "Run the modules again"
    And I should see "The generated modules run again."
