package com.example.employees.demo;

import org.springframework.stereotype.Component;

import java.util.ArrayList;
import java.util.Collections;
import java.util.List;

@Component
public class EmployeeRepository {

    private final List<Employee> employees = new ArrayList<>();

    public void add(Employee e) {
        employees.add(e);
    }

    public List<Employee> findAll() {
        return Collections.unmodifiableList(employees);
    }
}
