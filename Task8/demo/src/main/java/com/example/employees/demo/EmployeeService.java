package com.example.employees.demo;

import org.springframework.beans.factory.annotation.Autowired;
import org.springframework.stereotype.Component;

@Component
public class EmployeeService {

    private final EmployeeRepository repository;

    @Autowired
    public EmployeeService(EmployeeRepository repository) {
        this.repository = repository;
    }

    public void addSampleEmployees() {
        repository.add(new Employee(1, "Alice", "HR"));
        repository.add(new Employee(2, "Bob", "IT"));
        repository.add(new Employee(3, "Carol", "Finance"));
    }

    public void printAllEmployees() {
        System.out.println("=== Employee List ===");
        repository.findAll().forEach(System.out::println);
    }
}
