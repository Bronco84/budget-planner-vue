# Page snapshot

```yaml
- generic [ref=e3]:
  - link "logo" [ref=e5] [cursor=pointer]:
    - /url: /
    - img "logo" [ref=e6]
  - generic [ref=e7]:
    - button "Login as bamccoley@gmail.com" [ref=e9] [cursor=pointer]
    - generic [ref=e10]:
      - generic [ref=e11]:
        - generic [ref=e12]: Email
        - textbox "Email" [active] [ref=e13]
      - generic [ref=e14]:
        - generic [ref=e15]: Password
        - textbox "Password" [ref=e16]
      - generic [ref=e18]:
        - checkbox "Remember me" [ref=e19]
        - generic [ref=e20]: Remember me
      - generic [ref=e21]:
        - link "Forgot your password?" [ref=e22] [cursor=pointer]:
          - /url: https://budget-planner-vue.test/forgot-password
        - button "Log in" [ref=e23] [cursor=pointer]
```