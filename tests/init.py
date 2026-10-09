# Generated from Selenium IDE
# Test name: init
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_init:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_init(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'API Client Test')]")) == 0
    self.driver.find_element(By.LINK_TEXT, "New Project").click()
    self.driver.find_element(By.ID, "app_title").send_keys("API Client Test")
    self.driver.find_element(By.NAME, "purpose").find_element(By.CSS_SELECTOR, "*[value='0']").click()
    self.driver.find_element(By.ID, "project_template_radio1").click()
    self.driver.find_element(By.CSS_SELECTOR, "input[name=\"copyof\"][value=\"1\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, ".btn-primaryrc").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"ExternalModules/manager/project.php\"]").click()
    self.driver.find_element(By.ID, "external-modules-enable-modules-button").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "tr[data-module=\"api_client\"] button.enable-button")))
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-module=\"api_client\"] button.enable-button").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "tr[data-module=\"api_client\"] button.external-modules-configure-button")))
