@filter @filter_dimanalysis
Feature: Format dimensional-analysis expressions with the dimanalysis filter
  In order to show factor-label working the way it is taught
  As a teacher
  I need conversion chains laid out as stacked, cancelling fractions

  Background:
    Given the "dimanalysis" filter is "on"

  @javascript
  Scenario: A conversion chain in text is laid out as a fraction track
    Given the following "user" exists:
      | username    | dim1                                                              |
      | description | <p>At STP: 5 L x (1 mol / 22.4 L) x (46 g / 1 mol) of ethanol.</p>  |
    When I am on the "dim1" "user > profile" page logged in as "dim1"
    Then "//span[@class='filter-dimanalysis'][@role='img']" "xpath_element" should exist
    And "//span[@class='filter-dimanalysis-cancel']" "xpath_element" should exist

  @javascript
  Scenario: An explicit block is rendered and its markers are removed
    Given the following "user" exists:
      | username    | dim2                                            |
      | description | <p>[da] 2 mol x (58.44 g / 1 mol) = 116.9 g [/da]</p> |
    When I am on the "dim2" "user > profile" page logged in as "dim2"
    Then I should not see "[da]"
    And "//span[@class='filter-dimanalysis-result']" "xpath_element" should exist

  @javascript
  Scenario: A nocancel block keeps every unit intact
    Given the following "user" exists:
      | username    | dim3                                              |
      | description | <p>[da nocancel] 5 L x (1 mol / 22.4 L) [/da]</p>  |
    When I am on the "dim3" "user > profile" page logged in as "dim3"
    Then "//span[@class='filter-dimanalysis']" "xpath_element" should exist
    And "//span[@class='filter-dimanalysis-cancel']" "xpath_element" should not exist
