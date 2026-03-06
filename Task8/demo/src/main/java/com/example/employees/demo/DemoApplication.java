package com.example.employees.demo;

import org.springframework.boot.SpringApplication;
import org.springframework.boot.autoconfigure.SpringBootApplication;
import org.springframework.context.ConfigurableApplicationContext;

@SpringBootApplication
public class DemoApplication {

    public static void main(String[] args) {
        // Start Spring Boot -> creates IoC container (ApplicationContext)
        ConfigurableApplicationContext context =
                SpringApplication.run(DemoApplication.class, args);

        // IoC: ask container for EmployeeService bean
        EmployeeService service = context.getBean(EmployeeService.class);

        // Use it; dependencies are injected with @Autowired
        service.addSampleEmployees();
        service.printAllEmployees();
    }
}
