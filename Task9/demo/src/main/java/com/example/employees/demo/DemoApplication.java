package com.example.employees.demo;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.ConfigurableApplicationContext;

@SpringBootApplication
public class DemoApplication {

    public static void main(String[] args) {
        ConfigurableApplicationContext context =
                SpringApplication.run(DemoApplication.class, args);

        EmployeeService service = context.getBean(EmployeeService.class);
        service.addSampleEmployees();
        service.printAllEmployees();
    }
}
