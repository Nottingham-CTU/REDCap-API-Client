# Generated from Selenium IDE
# Test name: t01 - HTTP on form submit
import pytest
import time
import json
from selenium import webdriver
from selenium.webdriver.common.action_chains import ActionChains
from selenium.webdriver.common.by import By
from selenium.webdriver.common.keys import Keys
from selenium.webdriver.support import expected_conditions
from selenium.webdriver.support.wait import WebDriverWait

class Test_01_HTTP_on_form_submit:
  def setup_method(self, method):
    self.driver = self.selectedBrowser
    self.vars = {}
  def teardown_method(self, method):
    self.driver.quit()

  def test_01_HTTP_on_form_submit(self):
    self.driver.get("http://127.0.0.1/")
    self.driver.find_element(By.LINK_TEXT, "My Projects").click()
    assert len(self.driver.find_elements(By.XPATH, "//*[@id=\"table-proj_table\"][contains(.,'API Client Test')]")) > 0
    self.driver.find_element(By.LINK_TEXT, "API Client Test").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"prefix=api_client\"][href*=\"page=connections\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, "button[onclick*=\"prefix=api_client\"][onclick*=\"page=edit_connection\"]").click()
    self.driver.execute_script("$('#connform input[type=\"text\"], #connform input[type=\"password\"], #connform textarea').css('max-width','350px')")
    self.driver.find_element(By.NAME, "conn_label").send_keys("HTTP Connection")
    self.driver.find_element(By.NAME, "conn_type").find_element(By.CSS_SELECTOR, "*[value='http']").click()
    self.driver.find_element(By.CSS_SELECTOR, "input[name=\"conn_active\"][value=\"Y\"]").click()
    self.driver.find_element(By.NAME, "http_url").send_keys("https://echo.zuplo.io/?a=PHA&b=PHB")
    self.driver.find_element(By.NAME, "http_auth_ph_name").send_keys("PHA")
    self.driver.find_element(By.NAME, "http_auth_ph_value").send_keys("1")
    self.driver.find_element(By.ID, "http_add_ph").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"1\"] input[name=\"http_ph_name[]\"]").send_keys("PHB")
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"1\"] select[name=\"http_ph_field[]\"]").find_element(By.CSS_SELECTOR, "*[value='first_name']").click()
    self.driver.find_element(By.NAME, "http_response_format").find_element(By.CSS_SELECTOR, "*[value='J']").click()
    self.driver.find_element(By.ID, "http_add_response").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"1\"] select[name=\"http_response_field[]\"]").find_element(By.CSS_SELECTOR, "*[value='last_name']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"1\"] select[name=\"http_response_type[]\"]").find_element(By.CSS_SELECTOR, "*[value='R']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"1\"] input[name=\"http_response_val[]\"]").send_keys("//query/b")
    self.driver.find_element(By.ID, "http_add_response").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"2\"] select[name=\"http_response_field[]\"]").find_element(By.CSS_SELECTOR, "*[value='race']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"2\"] select[name=\"http_response_type[]\"]").find_element(By.CSS_SELECTOR, "*[value='R']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"2\"] input[name=\"http_response_val[]\"]").send_keys("//query/a")
    self.driver.find_element(By.ID, "http_add_response").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"3\"] select[name=\"http_response_field[]\"]").find_element(By.CSS_SELECTOR, "*[value='address']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"3\"] select[name=\"http_response_type[]\"]").find_element(By.CSS_SELECTOR, "*[value='C']").click()
    self.driver.find_element(By.CSS_SELECTOR, "tr[data-index=\"3\"] input[name=\"http_response_val[]\"]").send_keys("Example value")
    self.driver.find_element(By.CSS_SELECTOR, "input[type=\"submit\"]").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "a[href*=\"prefix=api_client\"][href*=\"page=edit_connection\"]")))
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"record_status_dashboard.php\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, "button[onclick*=\"record_home.php\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, "#event_grid_table a[href*=\"page=demographics\"]").click()
    self.driver.find_element(By.NAME, "first_name").send_keys("TestName")
    self.driver.find_element(By.CSS_SELECTOR, "button[id=\"submit-btn-saverecord\"]").click()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "#event_grid_table a[href*=\"page=demographics\"]")))
    self.driver.find_element(By.CSS_SELECTOR, "#event_grid_table a[href*=\"page=demographics\"]").click()
    self.driver.execute_script("//SETDESC:Assert that the fields now have the expected values following the API request.")
    self.driver.find_element(By.NAME, "address").send_keys("SAVESCREENSHOT")
    assert self.driver.find_element(By.NAME, "first_name").get_attribute("value") == "TestName"
    assert self.driver.find_element(By.NAME, "last_name").get_attribute("value") == "TestName"
    assert self.driver.find_element(By.NAME, "address").get_attribute("value") == "Example value"
    assert self.driver.find_element(By.NAME, "race").get_attribute("value") == "1"
    self.driver.find_element(By.NAME, "submit-btn-deleteform").click()
    self.driver.find_element(By.XPATH, "//button[contains(text(),'Delete data for THIS FORM only')]").click()
    self.driver.find_element(By.ID, "recordActionDropdownTrigger").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[onclick*=\"delete_record_dialog\"]").click()
    self.driver.find_element(By.XPATH, "//button[contains(@class,'ok-button')][contains(text(),'DELETE')]").click()
    self.driver.find_element(By.XPATH, "//button[contains(@class,'close-button')][text()='Close']").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"prefix=api_client\"][href*=\"page=connections\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[href*=\"prefix=api_client\"][href*=\"page=edit_connection\"][href*=\"conn_id=\"]").click()
    self.driver.find_element(By.CSS_SELECTOR, "a[onclick*=\"delconnform\"]").click()
    self.driver.switch_to.alert.accept()
    WebDriverWait(self.driver, 30).until(expected_conditions.presence_of_element_located((By.CSS_SELECTOR, "button[onclick*=\"prefix=api_client\"][onclick*=\"page=edit_connection\"]")))
